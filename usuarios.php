<?php

// =====================================================
// API - LISTAR USUARIOS
// =====================================================

// Indicamos que la respuesta será en formato JSON
header('Content-Type: application/json; charset=utf-8');

// Incluimos la conexión a la base de datos
require_once __DIR__ . '/conexion.php';

// Consulta para obtener los usuarios
// IMPORTANTE: no enviamos el campo password
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

// Ejecutamos la consulta
$result = $conn->query($sql);

// Si ocurre un error en la consulta
if (!$result) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Error al consultar usuarios'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// Creamos un arreglo para guardar los usuarios
$usuarios = [];

// Recorremos los resultados
while ($fila = $result->fetch_assoc()) {
    $usuarios[] = $fila;
}

// Enviamos la respuesta
echo json_encode([
    'success' => true,
    'total' => count($usuarios),
    'usuarios' => $usuarios
], JSON_UNESCAPED_UNICODE);

// Cerramos la conexión
$conn->close();
