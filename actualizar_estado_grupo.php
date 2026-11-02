<?php

/*
|--------------------------------------------------------------------------
| COMUNIMAP - ACTUALIZAR ESTADO DE CASOS AGRUPADOS
|--------------------------------------------------------------------------
|
| Funciones:
| - Comprobar la autenticación del administrador.
| - Recalcular el grupo usando la biblioteca existente.
| - Actualizar únicamente los reportes cuyo estado cambie.
| - Registrar notificaciones internas para cada propietario.
| - Enviar notificaciones Firebase después de guardar.
|
| No modifica las prioridades ni las coordenadas.
| No crea nuevas tablas.
|
*/

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// =====================================================
// VALIDAR MÉTODO HTTP
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
// CARGAR ARCHIVOS EXISTENTES
// =====================================================

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/autenticacion_api.php';
require_once __DIR__ . '/casos_agrupados_lib.php';
require_once __DIR__ . '/enviar_notificacion_push.php';

// =====================================================
// COMPROBAR ADMINISTRADOR
// =====================================================

cmExigirAdministrador($conn);

// =====================================================
// LEER Y VALIDAR DATOS
// =====================================================

$datos = json_decode(
    file_get_contents('php://input'),
    true
);

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
    $idReporte === null ||
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
// PREPARAR MENSAJES DE NOTIFICACIÓN
// =====================================================

$tituloNotificacion = '';
$mensajeNotificacion = '';
$tipoNotificacion = '';

if ($estado === 'En Proceso') {

    $tituloNotificacion = 'Reporte en proceso';

    $mensajeNotificacion =
        'Tu reporte está siendo atendido.';

    $tipoNotificacion = 'CAMBIO_ESTADO';

} elseif ($estado === 'Solucionado') {

    $tituloNotificacion = 'Reporte solucionado';

    $mensajeNotificacion =
        'Tu reporte ha sido solucionado.';

    $tipoNotificacion = 'CAMBIO_ESTADO';
}

// =====================================================
// INICIAR OPERACIÓN
// =====================================================

$transaccionIniciada = false;

$notificacionesParaEnviar = [];

$cantidadActualizada = 0;

$cantidadNotificaciones = 0;

$cantidadVecinos = 0;

$cantidadReportesGrupo = 0;

try {

    $conn->begin_transaction();

    $transaccionIniciada = true;

    /*
     * Bloquear reportes existentes para evitar
     * actualizaciones simultáneas mientras se
     * recalcula y modifica el grupo.
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

    // Obtener reportes actuales.
    $todosReportes = cmConsultarReportes($conn);

    // Recalcular los grupos reales.
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
     * El cliente solo envía el identificador
     * representativo. La API decide qué reportes
     * pertenecen realmente al grupo.
     */

    if ($grupoSeleccionado === null) {

        $conn->rollback();
        $transaccionIniciada = false;

        http_response_code(409);

        echo json_encode([
            'success' => false,
            'message' =>
                'Este caso cambió o ya no existe. '
                . 'Actualiza la lista e inténtalo nuevamente.'
        ], JSON_UNESCAPED_UNICODE);

        $conn->close();
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

    $cantidadVecinos =
        (int) $grupoSeleccionado['cantidadVecinos'];

    $cantidadReportesGrupo =
        count($idsReportes);

    if (
        $cantidadVecinos < 2 ||
        $cantidadReportesGrupo < 2
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

        $conn->close();
        exit;
    }

    // =================================================
    // CONSULTAS PREPARADAS
    // =================================================

    $actualizarReporte = $conn->prepare("
        UPDATE reportes
        SET estado = ?
        WHERE idReporte = ?
    ");

    if (!$actualizarReporte) {
        throw new RuntimeException(
            'No se pudo preparar la actualización'
        );
    }

    $actualizarReporte->bind_param(
        'si',
        $estadoParaGuardar,
        $idParaActualizar
    );

    /*
     * Las notificaciones internas se guardan
     * en la misma tabla que ya usa ComuniMap.
     *
     * Se utiliza una fila por reporte afectado.
     */

    $insertarNotificacion = $conn->prepare("
        INSERT INTO notificaciones
        (
            idUsuario,
            idReporte,
            titulo,
            mensaje,
            tipo,
            leida
        )
        VALUES (?, ?, ?, ?, ?, 0)
    ");

    if (!$insertarNotificacion) {
        throw new RuntimeException(
            'No se pudo preparar la notificación'
        );
    }

    $insertarNotificacion->bind_param(
        'iisss',
        $idDestinatario,
        $idNotificacionReporte,
        $tituloParaNotificacion,
        $mensajeParaNotificacion,
        $tipoParaNotificacion
    );

    $idsDelGrupo = array_fill_keys(
        $idsReportes,
        true
    );

    // =================================================
    // RECORRER LOS REPORTES DEL GRUPO
    // =================================================

    foreach ($todosReportes as $reporte) {

        $idActual = (int) $reporte['idReporte'];

        if (!isset($idsDelGrupo[$idActual])) {
            continue;
        }

        $estadoAnterior = trim(
            (string) ($reporte['estado'] ?? '')
        );

        /*
         * No generar cambios ni notificaciones
         * para reportes que ya tienen ese estado.
         */

        if (
            strcasecmp(
                $estadoAnterior,
                $estado
            ) === 0
        ) {
            continue;
        }

        // ---------------------------------------------
        // ACTUALIZAR ESTADO
        // ---------------------------------------------

        $idParaActualizar = $idActual;
        $estadoParaGuardar = $estado;

        if (!$actualizarReporte->execute()) {
            throw new RuntimeException(
                'No se pudo actualizar un reporte'
            );
        }

        $cantidadActualizada++;

        /*
         * Pendiente no genera aviso en esta etapa.
         */

        if ($estado === 'Pendiente') {
            continue;
        }

        // ---------------------------------------------
        // REGISTRAR NOTIFICACIÓN INTERNA
        // ---------------------------------------------

        $idDestinatario =
            (int) $reporte['idUsuario'];

        $idNotificacionReporte = $idActual;

        $tituloParaNotificacion =
            $tituloNotificacion;

        $mensajeParaNotificacion =
            $mensajeNotificacion;

        $tipoParaNotificacion =
            $tipoNotificacion;

        if (!$insertarNotificacion->execute()) {
            throw new RuntimeException(
                'No se pudo guardar una notificación'
            );
        }

        $cantidadNotificaciones++;

        /*
         * Guardar la información necesaria para
         * enviar Firebase después del commit.
         */

        $notificacionesParaEnviar[] = [
            'idUsuario' => $idDestinatario,
            'idReporte' => $idActual,
            'titulo' => $tituloNotificacion,
            'mensaje' => $mensajeNotificacion,
            'tipo' => $tipoNotificacion
        ];
    }

    $actualizarReporte->close();
    $insertarNotificacion->close();

    // =================================================
    // CONFIRMAR ESTADOS Y NOTIFICACIONES INTERNAS
    // =================================================

    $conn->commit();

    $transaccionIniciada = false;

} catch (Throwable $e) {

    if ($transaccionIniciada) {

        try {
            $conn->rollback();
        } catch (Throwable $errorRollback) {

            error_log(
                'ComuniMap: error al revertir grupo: ' .
                $errorRollback->getMessage()
            );
        }
    }

    error_log(
        'ComuniMap: error en estado grupal: ' .
        $e->getMessage()
    );

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' =>
            'No se pudo actualizar el estado del caso. '
            . 'Inténtalo nuevamente.'
    ], JSON_UNESCAPED_UNICODE);

    $conn->close();
    exit;
}

// =====================================================
// ENVIAR NOTIFICACIONES PUSH DESPUÉS DEL COMMIT
// =====================================================

$cantidadPushExitosos = 0;

foreach (
    $notificacionesParaEnviar
    as $notificacion
) {

    try {

        // Obtener dispositivos del propietario.
        $consultaTokens = $conn->prepare("
            SELECT token
            FROM dispositivos_fcm
            WHERE idUsuario = ?
            ORDER BY fechaActualizacion DESC
        ");

        if (!$consultaTokens) {
            throw new RuntimeException(
                'No se pudieron consultar los dispositivos'
            );
        }

        $idDestinatarioPush =
            (int) $notificacion['idUsuario'];

        $consultaTokens->bind_param(
            'i',
            $idDestinatarioPush
        );

        if (!$consultaTokens->execute()) {
            throw new RuntimeException(
                'No se pudieron consultar los tokens FCM'
            );
        }

        $resultadoTokens =
            $consultaTokens->get_result();

        while (
            $filaToken = $resultadoTokens->fetch_assoc()
        ) {

            $tokenDispositivo = trim(
                (string) $filaToken['token']
            );

            if ($tokenDispositivo === '') {
                continue;
            }

            /*
             * Reutilizar la misma función Firebase
             * que usa crear_reporte.php.
             */

            $resultadoPush = enviarNotificacionPush(
                $tokenDispositivo,
                $notificacion['titulo'],
                $notificacion['mensaje'],
                [
                    'tipo' => $notificacion['tipo'],
                    'idReporte' =>
                        (int) $notificacion['idReporte']
                ]
            );

            if (
                is_array($resultadoPush) &&
                !empty($resultadoPush['success'])
            ) {

                $cantidadPushExitosos++;

            } else {

                error_log(
                    'ComuniMap: Firebase no confirmó '
                    . 'el envío del reporte '
                    . $notificacion['idReporte']
                );
            }
        }

        $consultaTokens->close();

    } catch (Throwable $e) {

        /*
         * Firebase no debe revertir los estados
         * ni las notificaciones internas.
         */

        error_log(
            'ComuniMap: error enviando Firebase '
            . 'para el reporte '
            . $notificacion['idReporte']
            . ': '
            . $e->getMessage()
        );
    }
}

// =====================================================
// RESPUESTA FINAL PARA ANDROID
// =====================================================

http_response_code(200);

echo json_encode([
    'success' => true,

    'message' =>
        'Estado del caso agrupado actualizado '
        . 'correctamente',

    'idReporte' => (int) $idReporte,

    'estado' => $estado,

    'cantidadReportes' =>
        $cantidadReportesGrupo,

    'cantidadVecinos' =>
        $cantidadVecinos,

    'reportesActualizados' =>
        $cantidadActualizada,

    'notificacionesInternas' =>
        $cantidadNotificaciones,

    'enviosPushExitosos' =>
        $cantidadPushExitosos

], JSON_UNESCAPED_UNICODE);

$conn->close();
