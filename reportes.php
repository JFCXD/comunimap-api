<?php

// =====================================================
// API - LISTAR REPORTES
// =====================================================

// Indicamos que la respuesta será JSON
header('Content-Type: application/json; charset=utf-8');

// Incluimos la conexión
require_once __DIR__ . '/conexion.php';

// Consulta de reportes
// También obtenemos el nombre del usuario
// y el nombre de la categoría
$sql = "
    SELECT
        r.idReporte,
        r.titulo,
        r.descripcion,
        r.ubicacion,
        r.direccion,
        r.estado,
        r.imagen,
        r.idUsuario,
        u.nombre AS usuario,
        r.idCategoria,
        c.nombre AS categoria
    FROM reportes r

    INNER JOIN usuarios u
        ON r.idUsuario = u.idUsuario

    INNER JOIN categorias c
        ON r.idCategoria = c.idCategoria

    ORDER BY r.idReporte DESC
";

// Ejecutamos la consulta
$result = $conn->query($sql);

// Si ocurre un error
if (!$result) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Error al consultar reportes'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// Arreglo para almacenar los reportes
$reportes = [];

// Recorremos los resultados
while ($fila = $result->fetch_assoc()) {
    $reportes[] = $fila;
}

// Enviamos la respuesta
echo json_encode([
    'success' => true,
    'total' => count($reportes),
    'reportes' => $reportes
], JSON_UNESCAPED_UNICODE);

// Cerramos la conexión
$conn->close();
