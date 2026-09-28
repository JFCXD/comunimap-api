<?php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/conexion.php';

$sql = "
    SELECT
        idUsuario,
        nombre,
        email,
        telefono,
        rol,
        estado
    FROM usuarios
    ORDER BY idUsuario DESC
";

$result = $conn->query($sql);

if (!$result) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Error al consultar usuarios'
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
], JSON_UNESCAPED_UNICODE);

$conn->close();
