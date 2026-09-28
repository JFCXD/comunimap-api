<?php

// =====================================================
// API - LISTAR CATEGORÍAS
// =====================================================

// Indicamos que la respuesta será JSON
header('Content-Type: application/json; charset=utf-8');

// Incluimos la conexión
require_once __DIR__ . '/conexion.php';

// Consulta de categorías
$sql = "
    SELECT
        idCategoria,
        nombre,
        descripcion
    FROM categorias
    ORDER BY nombre ASC
";

// Ejecutamos la consulta
$result = $conn->query($sql);

// Si ocurre un error
if (!$result) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Error al consultar categorías'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// Arreglo donde se guardarán las categorías
$categorias = [];

// Recorremos los resultados
while ($fila = $result->fetch_assoc()) {
    $categorias[] = $fila;
}

// Enviamos la respuesta
echo json_encode([
    'success' => true,
    'total' => count($categorias),
    'categorias' => $categorias
], JSON_UNESCAPED_UNICODE);

// Cerramos la conexión
$conn->close();
