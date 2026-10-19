<?php

// =====================================================
// API - INICIO DE SESIÓN
// =====================================================

header('Content-Type: application/json; charset=utf-8');

// =====================================================
// VALIDAR MÉTODO HTTP
// =====================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Método no permitido'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// =====================================================
// CONEXIÓN A MYSQL
// =====================================================

require_once __DIR__ . '/conexion.php';

// =====================================================
// LEER JSON
// =====================================================

$datos = json_decode(
    file_get_contents('php://input'),
    true
);

// =====================================================
// VALIDAR DATOS
// =====================================================

if (
    empty($datos['email']) ||
    empty($datos['password'])
) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Correo y contraseña son obligatorios'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// =====================================================
// DATOS DEL LOGIN
// =====================================================

$email = trim($datos['email']);

// El sistema actual utiliza SHA1.
$password = sha1($datos['password']);

// =====================================================
// BUSCAR USUARIO
// =====================================================

// Primero buscamos por credenciales.
// NO filtramos estado aquí porque necesitamos distinguir
// entre contraseña incorrecta y cuenta pendiente.

$stmt = $conn->prepare("
    SELECT
        idUsuario,
        nombre,
        email,
        telefono,
        rol,
        estado
    FROM usuarios
    WHERE email = ?
      AND password = ?
    LIMIT 1
");

$stmt->bind_param(
    'ss',
    $email,
    $password
);

$stmt->execute();

$resultado = $stmt->get_result();

// =====================================================
// CREDENCIALES INCORRECTAS
// =====================================================

if ($resultado->num_rows === 0) {

    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Correo o contraseña incorrectos'
    ], JSON_UNESCAPED_UNICODE);

    $stmt->close();
    $conn->close();

    exit;
}

// =====================================================
// USUARIO ENCONTRADO
// =====================================================

$usuario = $resultado->fetch_assoc();

// =====================================================
// VALIDAR ESTADO
// =====================================================

if (
    strtolower(trim($usuario['estado'])) !== 'activo'
) {

    http_response_code(403);

    echo json_encode([
        'success' => false,
        'message' => 'Tu cuenta está pendiente de aprobación por un administrador.'
    ], JSON_UNESCAPED_UNICODE);

    $stmt->close();
    $conn->close();

    exit;
}

// =====================================================
// LOGIN CORRECTO
// =====================================================

echo json_encode([
    'success' => true,
    'message' => 'Inicio de sesión correcto',
    'usuario' => $usuario
], JSON_UNESCAPED_UNICODE);

// =====================================================
// CERRAR RECURSOS
// =====================================================

$stmt->close();
$conn->close();
