<?php

// =====================================================
// API - INICIO DE SESIÓN
// =====================================================

// Indicamos que la respuesta será en formato JSON
header('Content-Type: application/json; charset=utf-8');

// =====================================================
// VALIDAR MÉTODO HTTP
// =====================================================

// Este endpoint solamente acepta peticiones POST
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
// LEER JSON ENVIADO DESDE ANDROID
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

// IMPORTANTE:
// Laravel también utiliza SHA1 actualmente.
// Android envía la contraseña normal y PHP la convierte aquí.
$password = sha1($datos['password']);

// =====================================================
// BUSCAR USUARIO
// =====================================================

// Solamente permitimos iniciar sesión a usuarios Activos.
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
      AND estado = 'Activo'
    LIMIT 1
");

$stmt->bind_param(
    'ss',
    $email,
    $password
);

// Ejecutamos la consulta
$stmt->execute();

// Obtenemos el resultado
$resultado = $stmt->get_result();

// =====================================================
// USUARIO ENCONTRADO
// =====================================================

if ($resultado->num_rows > 0) {

    $usuario = $resultado->fetch_assoc();

    echo json_encode([
        'success' => true,
        'message' => 'Inicio de sesión correcto',
        'usuario' => $usuario
    ], JSON_UNESCAPED_UNICODE);

} else {

    // =================================================
    // CREDENCIALES INCORRECTAS
    // =================================================

    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Correo o contraseña incorrectos'
    ], JSON_UNESCAPED_UNICODE);
}

// =====================================================
// CERRAR RECURSOS
// =====================================================

$stmt->close();
$conn->close();
