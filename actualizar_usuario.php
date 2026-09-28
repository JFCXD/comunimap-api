<?php

// =====================================================
// API - ACTUALIZAR USUARIO
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

// Validamos datos obligatorios
if (
    empty($datos['idUsuario']) ||
    empty($datos['nombre']) ||
    empty($datos['email']) ||
    empty($datos['rol']) ||
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

$idUsuario = (int) $datos['idUsuario'];

$nombre = trim($datos['nombre']);
$email = trim($datos['email']);

$telefono = $datos['telefono'] ?? null;

$rol = trim($datos['rol']);
$estado = trim($datos['estado']);

// =====================================================
// ACTUALIZAR USUARIO
// =====================================================

$stmt = $conn->prepare("
    UPDATE usuarios
    SET
        nombre = ?,
        email = ?,
        telefono = ?,
        rol = ?,
        estado = ?
    WHERE idUsuario = ?
");

$stmt->bind_param(
    'sssssi',
    $nombre,
    $email,
    $telefono,
    $rol,
    $estado,
    $idUsuario
);

// Ejecutamos
if (!$stmt->execute()) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Error al actualizar usuario'
    ]);

    exit;
}

// Respondemos
echo json_encode([
    'success' => true,
    'message' => 'Usuario actualizado correctamente'
], JSON_UNESCAPED_UNICODE);

// Cerramos recursos
$stmt->close();
$conn->close();
