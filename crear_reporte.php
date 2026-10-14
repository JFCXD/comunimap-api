<?php

// =====================================================
// API - CREAR REPORTE
// =====================================================

header('Content-Type: application/json; charset=utf-8');

// Solo permitir POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Método no permitido'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// =====================================================
// CONEXIÓN Y PUSH
// =====================================================

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/enviar_notificacion_push.php';

// =====================================================
// LEER JSON
// =====================================================

$datos = json_decode(
    file_get_contents('php://input'),
    true
);

// =====================================================
// VALIDAR JSON
// =====================================================

if (!is_array($datos)) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'JSON inválido'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// =====================================================
// VALIDAR CAMPOS
// =====================================================

if (
    empty($datos['titulo']) ||
    empty($datos['descripcion']) ||
    empty($datos['ubicacion']) ||
    empty($datos['idUsuario']) ||
    empty($datos['idCategoria'])
) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Faltan datos obligatorios'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// =====================================================
// DATOS RECIBIDOS
// =====================================================

$titulo = trim($datos['titulo']);
$descripcion = trim($datos['descripcion']);
$ubicacion = trim($datos['ubicacion']);

$direccion = $datos['direccion'] ?? null;
$imagen = $datos['imagen'] ?? null;

$estado = 'Pendiente';

$idUsuario = (int) $datos['idUsuario'];
$idCategoria = (int) $datos['idCategoria'];

// =====================================================
// INICIAR TRANSACCIÓN
// =====================================================

$conn->begin_transaction();

try {

    // =====================================================
    // INSERTAR REPORTE
    // =====================================================

    $stmt = $conn->prepare("
        INSERT INTO reportes
        (
            titulo,
            descripcion,
            ubicacion,
            direccion,
            estado,
            idUsuario,
            idCategoria,
            imagen
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        throw new Exception(
            'No se pudo preparar la creación del reporte'
        );
    }

    $stmt->bind_param(
        'sssssiis',
        $titulo,
        $descripcion,
        $ubicacion,
        $direccion,
        $estado,
        $idUsuario,
        $idCategoria,
        $imagen
    );

    if (!$stmt->execute()) {
        throw new Exception(
            'No se pudo registrar el reporte'
        );
    }

    $idReporteNuevo = $conn->insert_id;

    // =====================================================
    // BUSCAR ADMINISTRADORES ACTIVOS
    // =====================================================

    $stmtAdmins = $conn->prepare("
        SELECT idUsuario
        FROM usuarios
        WHERE LOWER(TRIM(rol)) = 'administrador'
          AND LOWER(TRIM(estado)) = 'activo'
    ");

    if (!$stmtAdmins) {
        throw new Exception(
            'No se pudo preparar la consulta de administradores'
        );
    }

    $stmtAdmins->execute();

    $resultadoAdmins =
        $stmtAdmins->get_result();

    $idsAdministradores = [];

    // =====================================================
    // CREAR NOTIFICACIÓN INTERNA PARA CADA ADMIN
    // =====================================================

    $stmtNotificacion = $conn->prepare("
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

    if (!$stmtNotificacion) {
        throw new Exception(
            'No se pudo preparar la notificación'
        );
    }

    $tituloNotificacion = 'Nuevo reporte';

    $mensajeNotificacion =
        'Se ha registrado un nuevo reporte en ComuniMap.';

    $tipoNotificacion =
        'NUEVO_REPORTE';

    while (
        $admin = $resultadoAdmins->fetch_assoc()
    ) {

        $idAdministrador =
            (int) $admin['idUsuario'];

        $idsAdministradores[] =
            $idAdministrador;

        $stmtNotificacion->bind_param(
            'iisss',
            $idAdministrador,
            $idReporteNuevo,
            $tituloNotificacion,
            $mensajeNotificacion,
            $tipoNotificacion
        );

        if (!$stmtNotificacion->execute()) {
            throw new Exception(
                'No se pudo crear la notificación'
            );
        }
    }

    // =====================================================
    // CONFIRMAR CAMBIOS
    // =====================================================

    $conn->commit();

    // =====================================================
    // ENVIAR PUSH A LOS ADMINISTRADORES
    // =====================================================
    // Esto ocurre DESPUÉS del commit.
    // Si Firebase falla, el reporte sigue guardado.

    foreach (
        $idsAdministradores
        as $idAdministrador
    ) {

        try {

            $stmtTokens = $conn->prepare("
                SELECT token
                FROM dispositivos_fcm
                WHERE idUsuario = ?
                ORDER BY fechaActualizacion DESC
            ");

            if (!$stmtTokens) {
                throw new Exception(
                    'No se pudo preparar la consulta de tokens'
                );
            }

            $stmtTokens->bind_param(
                'i',
                $idAdministrador
            );

            $stmtTokens->execute();

            $resultadoTokens =
                $stmtTokens->get_result();

            while (
                $filaToken =
                    $resultadoTokens->fetch_assoc()
            ) {

                $tokenDispositivo =
                    trim($filaToken['token']);

                if ($tokenDispositivo === '') {
                    continue;
                }

                $resultadoPush =
                    enviarNotificacionPush(
                        $tokenDispositivo,
                        $tituloNotificacion,
                        $mensajeNotificacion,
                        [
                            'tipo' =>
                                $tipoNotificacion,

                            'idReporte' =>
                                $idReporteNuevo
                        ]
                    );

                if (
                    empty($resultadoPush['success'])
                ) {

                    error_log(
                        'Error push administrador ' .
                        $idAdministrador .
                        ': ' .
                        json_encode(
                            $resultadoPush,
                            JSON_UNESCAPED_UNICODE
                        )
                    );
                }
            }

            $stmtTokens->close();

        } catch (Throwable $e) {

            error_log(
                'Error enviando push al administrador ' .
                $idAdministrador .
                ': ' .
                $e->getMessage()
            );
        }
    }

    // =====================================================
    // RESPUESTA EXITOSA
    // =====================================================

    echo json_encode([
        'success' => true,
        'message' =>
            'Reporte registrado correctamente',

        'idReporte' =>
            $idReporteNuevo
    ], JSON_UNESCAPED_UNICODE);

    // =====================================================
    // CERRAR
    // =====================================================

    $stmtNotificacion->close();
    $stmtAdmins->close();
    $stmt->close();

} catch (Throwable $e) {

    // =====================================================
    // REVERTIR SI FALLA LA OPERACIÓN PRINCIPAL
    // =====================================================

    $conn->rollback();

    error_log(
        'Error crear_reporte.php: ' .
        $e->getMessage()
    );

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' =>
            'Error al registrar reporte'
    ], JSON_UNESCAPED_UNICODE);
}

$conn->close();
