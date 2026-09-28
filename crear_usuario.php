<?php

// =====================================================
// API - CREAR USUARIO
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

// Leemos los datos enviados
$datos = json_decode(file_get_contents('php://input'), true);

// =====================================================
// VALIDACIÓN
// =====================================================

if (
    empty($datos['nombre']) ||
    empty($datos['email']) ||
    empty($datos['password']) ||
    empty($datos['rol'])
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

$nombre = trim($datos['nombre']);
$email = trim($datos['email']);

$telefono = $datos['telefono'] ?? null;

$rol = trim($datos['rol']);

// Estado inicial
$estado = 'Activo';

// Encriptamos la contraseña
$password = password_hash(
    $datos['password'],
    PASSWORD_DEFAULT
);

// =====================================================
// INSERTAR USUARIO
// =====================================================

$stmt = $conn->prepare("
    INSERT INTO usuarios
    (
        nombre,
        email,
        password,
        telefono,
        rol,
        estado
    )
    VALUES (?, ?, ?, ?, ?, ?)
");

$stmt->bind_param(
    'ssssss',
    $nombre,
    $email,
    $password,
    $telefono,
    $rol,
    $estado
);

// Ejecutamos
if (!$stmt->execute()) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Error al crear usuario'
    ]);

    exit;
}

// =====================================================
// RESPUESTA
// =====================================================

echo json_encode([
    'success' => true,
    'message' => 'Usuario creado correctamente',
    'idUsuario' => $stmt->insert_id
], JSON_UNESCAPED_UNICODE);

// Cerramos recursos
$stmt->close();
$conn->close();
