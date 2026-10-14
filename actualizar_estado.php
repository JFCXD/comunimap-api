<?php

// =====================================================
// API - ACTUALIZAR ESTADO DE REPORTE
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
    empty($datos['idReporte']) ||
    empty($datos['estado'])
) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' =>
            'idReporte y estado son obligatorios'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$idReporte =
    (int) $datos['idReporte'];

$nuevoEstado =
    trim($datos['estado']);

// =====================================================
// VALIDAR ESTADOS
// =====================================================

$estadosPermitidos = [
    'Pendiente',
    'En Proceso',
    'Solucionado'
];

if (
    !in_array(
        $nuevoEstado,
        $estadosPermitidos,
        true
    )
) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Estado no válido'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// =====================================================
// INICIAR TRANSACCIÓN
// =====================================================

$conn->begin_transaction();

try {

    // =====================================================
    // OBTENER DATOS ACTUALES DEL REPORTE
    // =====================================================

    $stmtReporte = $conn->prepare("
        SELECT
            idUsuario,
            titulo,
            estado
        FROM reportes
        WHERE idReporte = ?
        LIMIT 1
    ");

    if (!$stmtReporte) {
        throw new Exception(
            'No se pudo preparar la consulta del reporte'
        );
    }

    $stmtReporte->bind_param(
        'i',
        $idReporte
    );

    $stmtReporte->execute();

    $resultadoReporte =
        $stmtReporte->get_result();

    if (
        $resultadoReporte->num_rows === 0
    ) {

        $conn->rollback();

        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Reporte no encontrado'
        ], JSON_UNESCAPED_UNICODE);

        $stmtReporte->close();
        $conn->close();

        exit;
    }

    $reporte =
        $resultadoReporte->fetch_assoc();

    $idUsuarioReporte =
        (int) $reporte['idUsuario'];

    $tituloReporte =
        $reporte['titulo'];

    $estadoAnterior =
        $reporte['estado'];

    // =====================================================
    // EVITAR MISMO ESTADO
    // =====================================================

    if (
        strcasecmp(
            trim($estadoAnterior),
            trim($nuevoEstado)
        ) === 0
    ) {

        $conn->rollback();

        echo json_encode([
            'success' => true,
            'message' =>
                'El reporte ya tiene ese estado'
        ], JSON_UNESCAPED_UNICODE);

        $stmtReporte->close();
        $conn->close();

        exit;
    }

    // =====================================================
    // ACTUALIZAR ESTADO
    // =====================================================

    $stmtActualizar = $conn->prepare("
        UPDATE reportes
        SET estado = ?
        WHERE idReporte = ?
    ");

    if (!$stmtActualizar) {
        throw new Exception(
            'No se pudo preparar la actualización'
        );
    }

    $stmtActualizar->bind_param(
        'si',
        $nuevoEstado,
        $idReporte
    );

    if (!$stmtActualizar->execute()) {
        throw new Exception(
            'No se pudo actualizar el estado'
        );
    }

    // =====================================================
    // PREPARAR NOTIFICACIÓN
    // =====================================================

    $crearNotificacion = false;

    $tituloNotificacion = '';
    $mensajeNotificacion = '';
    $tipoNotificacion = '';

    // =====================================================
    // EN PROCESO
    // =====================================================

    if (
        strcasecmp(
            $nuevoEstado,
            'En Proceso'
        ) === 0
    ) {

        $crearNotificacion = true;

        $tituloNotificacion =
            'Tu reporte está siendo atendido';

        $mensajeNotificacion =
            'El reporte "' .
            $tituloReporte .
            '" cambió a En Proceso.';

        $tipoNotificacion =
            'EN_PROCESO';
    }

    // =====================================================
    // SOLUCIONADO
    // =====================================================

    if (
        strcasecmp(
            $nuevoEstado,
            'Solucionado'
        ) === 0
    ) {

        $crearNotificacion = true;

        $tituloNotificacion =
            'Tu reporte fue solucionado';

        $mensajeNotificacion =
            'El reporte "' .
            $tituloReporte .
            '" fue marcado como Solucionado.';

        $tipoNotificacion =
            'SOLUCIONADO';
    }

    // =====================================================
    // CREAR NOTIFICACIÓN INTERNA
    // =====================================================

    if ($crearNotificacion) {

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

        $stmtNotificacion->bind_param(
            'iisss',
            $idUsuarioReporte,
            $idReporte,
            $tituloNotificacion,
            $mensajeNotificacion,
            $tipoNotificacion
        );

        if (
            !$stmtNotificacion->execute()
        ) {

            throw new Exception(
                'No se pudo crear la notificación'
            );
        }

        $stmtNotificacion->close();
    }

    // =====================================================
    // CONFIRMAR CAMBIOS
    // =====================================================

    $conn->commit();

    // =====================================================
    // ENVIAR PUSH AL DUEÑO
    // =====================================================
    // Solo si se generó una notificación.
    // Se ejecuta después del commit.

    if ($crearNotificacion) {

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
                $idUsuarioReporte
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

                if (
                    $tokenDispositivo === ''
                ) {
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
                                $idReporte
                        ]
                    );

                if (
                    empty(
                        $resultadoPush['success']
                    )
                ) {

                    error_log(
                        'Error push usuario ' .
                        $idUsuarioReporte .
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
                'Error enviando push al usuario ' .
                $idUsuarioReporte .
                ': ' .
                $e->getMessage()
            );
        }
    }

    // =====================================================
    // RESPUESTA
    // =====================================================

    echo json_encode([
        'success' => true,
        'message' =>
            'Estado actualizado correctamente',

        'estadoAnterior' =>
            $estadoAnterior,

        'estadoNuevo' =>
            $nuevoEstado
    ], JSON_UNESCAPED_UNICODE);

    // =====================================================
    // CERRAR
    // =====================================================

    $stmtActualizar->close();
    $stmtReporte->close();

} catch (Throwable $e) {

    // Si el commit todavía no ocurrió,
    // se revierten los cambios principales.
    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
    }

    error_log(
        'Error actualizar_estado.php: ' .
        $e->getMessage()
    );

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' =>
            'Error al actualizar el estado'
    ], JSON_UNESCAPED_UNICODE);
}

$conn->close();
