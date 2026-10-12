<?php

// API - OBTENER NOTIFICACIONES DEL USUARIO

header('Content-Type: application/json; charset=utf-8');

// Solo permitir método GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Método no permitido'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

require_once __DIR__ . '/conexion.php';

// Verificar que llegue idUsuario
if (!isset($_GET['idUsuario']) || empty($_GET['idUsuario'])) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'El idUsuario es obligatorio'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$idUsuario = intval($_GET['idUsuario']);

// Consulta segura
$stmt = $conn->prepare("
    SELECT
        idNotificacion,
        idUsuario,
        idReporte,
        titulo,
        mensaje,
        tipo,
        leida,
        fecha
    FROM notificaciones
    WHERE idUsuario = ?
    ORDER BY fecha DESC, idNotificacion DESC
");

$stmt->bind_param('i', $idUsuario);

$stmt->execute();

$resultado = $stmt->get_result();

$notificaciones = [];

while ($fila = $resultado->fetch_assoc()) {

    // Convertir leida a número
    $fila['leida'] = intval($fila['leida']);

    // Convertir IDs a números
    $fila['idNotificacion'] = intval($fila['idNotificacion']);
    $fila['idUsuario'] = intval($fila['idUsuario']);

    if ($fila['idReporte'] !== null) {
        $fila['idReporte'] = intval($fila['idReporte']);
    }

    $notificaciones[] = $fila;
}

echo json_encode([
    'success' => true,
    'total' => count($notificaciones),
    'notificaciones' => $notificaciones
], JSON_UNESCAPED_UNICODE);

$stmt->close();
$conn->close();
