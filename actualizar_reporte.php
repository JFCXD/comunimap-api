<?php

// =====================================================
// API - ACTUALIZAR REPORTE
// =====================================================

// Indicamos que la respuesta será JSON
header('Content-Type: application/json; charset=utf-8');

// Solo permitimos POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Método no permitido'
    ]);

    exit;
}

// Incluimos la conexión
require_once __DIR__ . '/conexion.php';

// Leemos el JSON recibido
$datos = json_decode(file_get_contents('php://input'), true);

// =====================================================
// VALIDACIÓN
// =====================================================

if (
    empty($datos['idReporte']) ||
    empty($datos['titulo']) ||
    empty($datos['descripcion']) ||
    empty($datos['ubicacion']) ||
    empty($datos['idCategoria']) ||
    empty($datos['estado'])
) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Faltan datos obligatorios'
    ]);

    exit;
}

// =====================================================
// DATOS
// =====================================================

$idReporte = (int) $datos['idReporte'];

$titulo = trim($datos['titulo']);
$descripcion = trim($datos['descripcion']);
$ubicacion = trim($datos['ubicacion']);

$direccion = $datos['direccion'] ?? null;

$idCategoria = (int) $datos['idCategoria'];

$estado = trim($datos['estado']);

// =====================================================
// ACTUALIZAR EN LA BASE DE DATOS
// =====================================================

$stmt = $conn->prepare("
    UPDATE reportes
    SET
        titulo = ?,
        descripcion = ?,
        ubicacion = ?,
        direccion = ?,
        idCategoria = ?,
        estado = ?
    WHERE idReporte = ?
");

// Asignamos los valores
$stmt->bind_param(
    'ssssisi',
    $titulo,
    $descripcion,
    $ubicacion,
    $direccion,
    $idCategoria,
    $estado,
    $idReporte
);

// Ejecutamos
if (!$stmt->execute()) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Error al actualizar reporte'
    ]);

    exit;
}

// =====================================================
// RESPUESTA
// =====================================================

echo json_encode([
    'success' => true,
    'message' => 'Reporte actualizado correctamente'
], JSON_UNESCAPED_UNICODE);

// Cerramos recursos
$stmt->close();
$conn->close();
