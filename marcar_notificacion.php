<?php

// API - MARCAR NOTIFICACIÓN COMO LEÍDA

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

require_once __DIR__ . '/conexion.php';

// Leer JSON recibido
$datos = json_decode(file_get_contents('php://input'), true);

// Validar idNotificacion
if (empty($datos['idNotificacion'])) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'El idNotificacion es obligatorio'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$idNotificacion = intval($datos['idNotificacion']);

// Actualizar notificación
$stmt = $conn->prepare("
    UPDATE notificaciones
    SET leida = 1
    WHERE idNotificacion = ?
");

$stmt->bind_param('i', $idNotificacion);

$stmt->execute();

// Verificar si realmente se encontró/modificó la notificación
if ($stmt->affected_rows > 0) {

    echo json_encode([
        'success' => true,
        'message' => 'Notificación marcada como leída'
    ], JSON_UNESCAPED_UNICODE);

} else {

    echo json_encode([
        'success' => false,
        'message' => 'No se encontró la notificación o ya estaba marcada como leída'
    ], JSON_UNESCAPED_UNICODE);
}

$stmt->close();
$conn->close();
