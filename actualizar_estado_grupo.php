<?php

/*
|--------------------------------------------------------------------------
| COMUNIMAP - ACTUALIZAR ESTADO DE CASO AGRUPADO
|--------------------------------------------------------------------------
|
| Cambia el estado de todos los reportes pertenecientes
| a un caso comunitario agrupado.
|
| Reglas:
| - Solo administradores activos autenticados.
| - Misma categoría.
| - Distancia máxima de 5 metros.
| - Reportes no solucionados.
| - Dos o más vecinos diferentes.
| - Una sola transacción para todo el grupo.
|
| No modifica prioridad, imágenes ni otros datos.
| No envía notificaciones en esta etapa.
|
*/

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// =====================================================
// PERMITIR ÚNICAMENTE POST
// =====================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    header('Allow: POST');

    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Método no permitido'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// =====================================================
// CARGAR ARCHIVOS
// =====================================================

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/autenticacion_api.php';
require_once __DIR__ . '/casos_agrupados_lib.php';

// =====================================================
// COMPROBAR ADMINISTRADOR ACTIVO
// =====================================================

// La función valida el token enviado por Android
// y consulta el rol y estado reales en MySQL.
cmExigirAdministrador($conn);

// =====================================================
// LEER DATOS JSON
// =====================================================

$datos = json_decode(
    file_get_contents('php://input'),
    true
);

// =====================================================
// VALIDAR DATOS RECIBIDOS
// =====================================================

if (!is_array($datos)) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Los datos enviados no son válidos'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$idReporte = filter_var(
    $datos['idReporte'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$estado = $datos['estado'] ?? null;

if (
    $idReporte === false ||
    !is_string($estado)
) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Identificador o estado no válido'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$estado = trim($estado);

$estadosPermitidos = [
    'Pendiente',
    'En Proceso',
    'Solucionado'
];

if (!in_array($estado, $estadosPermitidos, true)) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'El estado seleccionado no es válido'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// =====================================================
// INICIAR ACTUALIZACIÓN SEGURA
// =====================================================

$transaccionIniciada = false;

try {

    $conn->begin_transaction();

    $transaccionIniciada = true;

    /*
     * Bloquear los reportes existentes durante
     * la comprobación y actualización.
     *
     * De esta manera evitamos que otra operación
     * modifique esos registros simultáneamente.
     */
    $bloqueo = $conn->query("
        SELECT idReporte
        FROM reportes
        ORDER BY idReporte
        FOR UPDATE
    ");

    if (!$bloqueo) {
        throw new RuntimeException(
            'No se pudieron bloquear los reportes'
        );
    }

    $bloqueo->free();

    // Obtener los reportes actuales.
    $todosReportes = cmConsultarReportes($conn);

    // Recalcular los grupos en el servidor.
    $grupos = cmAgruparCasos($todosReportes);

    $grupoSeleccionado = null;

    foreach ($grupos as $grupo) {

        if (
            (int) $grupo['idReporte'] ===
            (int) $idReporte
        ) {

            $grupoSeleccionado = $grupo;

            break;
        }
    }

    /*
     * No confiar en los IDs de integrantes
     * que pudiera enviar un cliente.
     *
     * La biblioteca calcula nuevamente el grupo
     * según los datos actuales de MySQL.
     */
    if ($grupoSeleccionado === null) {

        $conn->rollback();
        $transaccionIniciada = false;

        http_response_code(409);

        echo json_encode([
            'success' => false,
            'message' =>
                'Este caso agrupado cambió o ya no existe. '
                . 'Actualiza la lista e inténtalo nuevamente.'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    $idsReportes = array_values(
        array_unique(
            array_map(
                'intval',
                $grupoSeleccionado['idsReportes']
            )
        )
    );

    if (
        count($idsReportes) < 2 ||
        (int) $grupoSeleccionado['cantidadVecinos'] < 2
    ) {

        $conn->rollback();
        $transaccionIniciada = false;

        http_response_code(409);

        echo json_encode([
            'success' => false,
            'message' =>
                'El caso ya no tiene suficientes '
                . 'reportes coincidentes.'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    // =================================================
    // PREPARAR ACTUALIZACIÓN GRUPAL
    // =================================================

    /*
     * Crear tantos marcadores ? como reportes
     * pertenezcan al grupo.
     */
    $marcadores = implode(
        ',',
        array_fill(
            0,
            count($idsReportes),
            '?'
        )
    );

    $sql = "
        UPDATE reportes
        SET estado = ?
        WHERE idReporte IN ($marcadores)
    ";

    $consulta = $conn->prepare($sql);

    if (!$consulta) {
        throw new RuntimeException(
            'No se pudo preparar la actualización'
        );
    }

    /*
     * Primer parámetro: estado.
     * Siguientes parámetros: IDs de reportes.
     */
    $tipos = 's' . str_repeat(
        'i',
        count($idsReportes)
    );

    $parametros = array_merge(
        [$estado],
        $idsReportes
    );

    $consulta->bind_param(
        $tipos,
        ...$parametros
    );

    if (!$consulta->execute()) {

        $consulta->close();

        throw new RuntimeException(
            'No se pudo actualizar el grupo'
        );
    }

    $consulta->close();

    // Guardar todos los cambios juntos.
    $conn->commit();

    $transaccionIniciada = false;

    // =================================================
    // RESPONDER A ANDROID
    // =================================================

    http_response_code(200);

    echo json_encode([
        'success' => true,

        'message' =>
            'Estado del caso agrupado actualizado '
            . 'correctamente',

        'idReporte' =>
            (int) $idReporte,

        'estado' => $estado,

        'cantidadReportes' =>
            count($idsReportes),

        'cantidadVecinos' =>
            (int) $grupoSeleccionado['cantidadVecinos']

    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {

    if ($transaccionIniciada) {

        try {
            $conn->rollback();
        } catch (Throwable $errorRollback) {
            error_log(
                'Error al revertir transacción: '
                . $errorRollback->getMessage()
            );
        }
    }

    // Registrar detalles únicamente en el servidor.
    error_log(
        'Error al actualizar estado grupal: '
        . $e->getMessage()
    );

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' =>
            'No se pudo actualizar el estado del caso. '
            . 'Inténtalo nuevamente.'
    ], JSON_UNESCAPED_UNICODE);
}

// =====================================================
// CERRAR CONEXIÓN
// =====================================================

$conn->close();
