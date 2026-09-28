<?php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/conexion.php';

$sql = "SELECT * FROM usuarios";

$result = $conn->query($sql);

if (!$result) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' => 'Error al consultar usuarios',
        'detalle' => $conn->error
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$usuarios = [];

while ($fila = $result->fetch_assoc()) {
    $usuarios[] = $fila;
}

echo json_encode([
    'success' => true,
    'total' => count($usuarios),
    'usuarios' => $usuarios
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

$conn->close();
