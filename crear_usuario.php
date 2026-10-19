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
    ], JSON_UNESCAPED_UNICODE);

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
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// =====================================================
// DATOS
// =====================================================

$nombre = trim($datos['nombre']);
$email = trim($datos['email']);
$telefono = $datos['telefono'] ?? null;

// IMPORTANTE:
// El registro público desde Android solamente debe crear usuarios normales.
$rol = 'Usuario';

// Toda cuenta registrada públicamente queda pendiente de aprobación.
$estado = 'Inactivo';

// =====================================================
// VERIFICAR CORREO DUPLICADO
// =====================================================

$stmtCorreo = $conn->prepare("
    SELECT idUsuario
    FROM usuarios
    WHERE email = ?
    LIMIT 1
");

$stmtCorreo->bind_param(
    's',
    $email
);

$stmtCorreo->execute();

$resultadoCorreo = $stmtCorreo->get_result();

if ($resultadoCorreo->num_rows > 0) {

    http_response_code(409);

    echo json_encode([
        'success' => false,
        'message' => 'Ya existe una cuenta registrada con este correo.'
    ], JSON_UNESCAPED_UNICODE);

    $stmtCorreo->close();
    $conn->close();

    exit;
}

$stmtCorreo->close();

// =====================================================
// CONTRASEÑA
// =====================================================

// El sistema actual utiliza SHA1 tanto en Laravel como en la API.
// Android envía la contraseña normal.
$password = sha1($datos['password']);

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
    ], JSON_UNESCAPED_UNICODE);

    $stmt->close();
    $conn->close();

    exit;
}

// =====================================================
// RESPUESTA
// =====================================================

echo json_encode([
    'success' => true,
    'message' => 'Cuenta creada correctamente. Tu cuenta está pendiente de aprobación por un administrador.',
    'idUsuario' => $stmt->insert_id
], JSON_UNESCAPED_UNICODE);

// Cerramos recursos
$stmt->close();
$conn->close();
