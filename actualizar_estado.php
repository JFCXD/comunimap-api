<?php

// =====================================================
// API - ACTUALIZAR ESTADO DE REPORTE
// =====================================================

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

// Leemos los datos JSON
$datos = json_decode(file_get_contents('php://input'), true);

// Validamos
if (
    empty($datos['idReporte']) ||
    empty($datos['estado'])
) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Faltan datos obligatorios'
    ]);

    exit;
}

// Datos recibidos
$idReporte = (int) $datos['idReporte'];
$estado = trim($datos['estado']);

// Preparamos la consulta
$stmt = $conn->prepare("
    UPDATE reportes
    SET estado = ?
    WHERE idReporte = ?
");

// Asignamos valores
$stmt->bind_param(
    'si',
    $estado,
    $idReporte
);

// Ejecutamos
if (!$stmt->execute()) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Error al actualizar estado'
    ]);

    exit;
}

// Respondemos
echo json_encode([
    'success' => true,
    'message' => 'Estado actualizado correctamente'
], JSON_UNESCAPED_UNICODE);

// Cerramos recursos
$stmt->close();
$conn->close();
