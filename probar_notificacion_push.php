<?php

header('Content-Type: application/json; charset=utf-8');

require_once 'conexion.php';
require_once 'enviar_notificacion_push.php';

/**
 * ============================================================
 * PRUEBA TEMPORAL DE NOTIFICACIÓN PUSH
 * ============================================================
 *
 * Busca el token FCM real del usuario indicado
 * y envía una notificación de prueba.
 *
 * ELIMINAR este archivo cuando terminemos las pruebas.
 */

$idUsuario = 5;

// Buscar el token real más reciente del usuario.
$stmt = $conn->prepare("
    SELECT token
    FROM dispositivos_fcm
    WHERE idUsuario = ?
      AND token <> 'TOKEN_DE_PRUEBA_123'
    ORDER BY fechaActualizacion DESC, idDispositivo DESC
    LIMIT 1
");

$stmt->bind_param("i", $idUsuario);
$stmt->execute();

$resultado = $stmt->get_result();

if ($resultado->num_rows === 0) {
    http_response_code(404);

    echo json_encode([
        'success' => false,
        'message' => 'No se encontró token FCM para el usuario.'
    ]);

    exit;
}

$fila = $resultado->fetch_assoc();
$token = $fila['token'];

$resultadoPush = enviarNotificacionPush(
    $token,
    'Prueba automática ComuniMap',
    'Esta notificación fue enviada desde Railway usando PHP y Firebase.',
    [
        'tipo' => 'PRUEBA',
        'idUsuario' => $idUsuario
    ]
);

echo json_encode(
    $resultadoPush,
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
);
