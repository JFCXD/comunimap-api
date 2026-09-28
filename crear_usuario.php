<?php

// =====================================================
// API - CREAR USUARIO
// =====================================================

// Indicamos que la respuesta será JSON
header('Content-Type: application/json; charset=utf-8');

// Solo permitimos peticiones POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Método no permitido'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// Incluimos la conexión a la base de datos
require_once __DIR__ . '/conexion.php';

// Leemos los datos JSON enviados desde Android
$datos = json_decode(file_get_contents('php://input'), true);

// =====================================================
// VALIDACIÓN DE DATOS
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
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// =====================================================
// DATOS RECIBIDOS
// =====================================================

// Nombre del usuario
$nombre = trim($datos['nombre']);

// Correo electrónico
$email = trim($datos['email']);

// Teléfono opcional
$telefono = $datos['telefono'] ?? null;

// Rol recibido
$rol = trim($datos['rol']);

// Estado inicial del usuario
$estado = 'Activo';

// =====================================================
// CONTRASEÑA
// =====================================================

// IMPORTANTE:
// La página web actualmente valida las contraseñas usando SHA1.
// Por eso usamos el mismo formato aquí para que los usuarios
// creados desde Android también puedan iniciar sesión en la web.

$password = sha1($datos['password']);

// =====================================================
// INSERTAR USUARIO
// =====================================================

// Preparamos la consulta para evitar SQL Injection
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

// Asignamos los valores a la consulta
$stmt->bind_param(
    'ssssss',
    $nombre,
    $email,
    $password,
    $telefono,
    $rol,
    $estado
);

// =====================================================
// EJECUTAR CONSULTA
// =====================================================

if (!$stmt->execute()) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Error al crear usuario'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// =====================================================
// RESPUESTA EXITOSA
// =====================================================

echo json_encode([
    'success' => true,
    'message' => 'Usuario creado correctamente',
    'idUsuario' => $stmt->insert_id
], JSON_UNESCAPED_UNICODE);

// =====================================================
// CERRAR RECURSOS
// =====================================================

$stmt->close();
$conn->close();
