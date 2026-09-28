<?php

// =====================================================
// API - CAMBIAR ESTADO DE USUARIO
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

// Leemos el JSON
$datos = json_decode(file_get_contents('php://input'), true);

// Validamos
if (
    empty($datos['idUsuario']) ||
    empty($datos['estado'])
) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Faltan datos obligatorios'
    ]);

    exit;
}

// Datos
$idUsuario = (int) $datos['idUsuario'];
$estado = trim($datos['estado']);

// Actualizamos
$stmt = $conn->prepare("
    UPDATE usuarios
    SET estado = ?
    WHERE idUsuario = ?
");

// Asignamos valores
$stmt->bind_param(
    'si',
    $estado,
    $idUsuario
);

// Ejecutamos
if (!$stmt->execute()) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Error al cambiar estado'
    ]);

    exit;
}

// Respondemos
echo json_encode([
    'success' => true,
    'message' => 'Estado del usuario actualizado correctamente'
], JSON_UNESCAPED_UNICODE);

// Cerramos recursos
$stmt->close();
$conn->close();
