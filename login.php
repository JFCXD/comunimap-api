<?php

/*
|--------------------------------------------------------------------------
| COMUNIMAP - INICIO DE SESIÓN
|--------------------------------------------------------------------------
|
| Mantiene el inicio de sesión actual.
|
| Nueva funcionalidad:
| Si el usuario es un Administrador activo,
| también genera un token firmado para autorizar
| operaciones administrativas desde Android.
|
| No cambia las contraseñas existentes.
| No modifica tablas de MySQL.
|
*/

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// =====================================================
// VALIDAR MÉTODO HTTP
// =====================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    header('Allow: POST');

    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Método no permitido'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// =====================================================
// CARGAR ARCHIVOS NECESARIOS
// =====================================================

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/autenticacion_api.php';

// =====================================================
// LEER DATOS ENVIADOS DESDE ANDROID
// =====================================================

$datos = json_decode(
    file_get_contents('php://input'),
    true
);

// =====================================================
// VALIDAR CAMPOS
// =====================================================

if (
    !is_array($datos) ||
    !isset($datos['email'], $datos['password']) ||
    !is_string($datos['email']) ||
    !is_string($datos['password']) ||
    trim($datos['email']) === '' ||
    $datos['password'] === ''
) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Correo y contraseña son obligatorios'
    ], JSON_UNESCAPED_UNICODE);

    $conn->close();
    exit;
}

// =====================================================
// PREPARAR DATOS
// =====================================================

$email = trim($datos['email']);

// Se conserva SHA-1 únicamente por compatibilidad
// con las contraseñas actuales de ComuniMap.
// La migración a password_hash será otra etapa.
$password = sha1($datos['password']);

// =====================================================
// CONSULTAR USUARIO ACTIVO
// =====================================================

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

if (!$stmt) {

    error_log(
        'ComuniMap: Error al preparar consulta de login'
    );

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'No se pudo iniciar sesión. Inténtalo nuevamente.'
    ], JSON_UNESCAPED_UNICODE);

    $conn->close();
    exit;
}

$stmt->bind_param(
    'ss',
    $email,
    $password
);

$stmt->execute();

$resultado = $stmt->get_result();

// =====================================================
// VALIDAR CREDENCIALES
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
// INICIO DE SESIÓN CORRECTO
// =====================================================

$usuario = $resultado->fetch_assoc();

$stmt->close();

// Mantener la respuesta conocida por Android.
$respuesta = [
    'success' => true,
    'message' => 'Inicio de sesión correcto',
    'usuario' => $usuario
];

// =====================================================
// TOKEN SOLO PARA ADMINISTRADORES ACTIVOS
// =====================================================

// Se verifican espacios y mayúsculas/minúsculas.
$esAdministrador = (
    strcasecmp(
        trim((string) $usuario['rol']),
        'Administrador'
    ) === 0
);

$estaActivo = (
    strcasecmp(
        trim((string) $usuario['estado']),
        'Activo'
    ) === 0
);

if ($esAdministrador && $estaActivo) {

    try {

        // Esta función está definida en
        // autenticacion_api.php
        $token = cmCrearToken(
            (int) $usuario['idUsuario']
        );

        // Campo adicional para Android.
        // No se reemplaza el objeto usuario.
        $respuesta['tokenApi'] = $token;

    } catch (Throwable $e) {

        // No revelar la clave ni detalles internos.
        error_log(
            'ComuniMap: No se pudo generar el token '
            . 'administrativo: '
            . $e->getMessage()
        );

        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' =>
                'No se pudo completar el inicio de sesión '
                . 'administrativo. Inténtalo nuevamente.'
        ], JSON_UNESCAPED_UNICODE);

        $conn->close();

        exit;
    }
}

// =====================================================
// RESPONDER AL CLIENTE
// =====================================================

http_response_code(200);

echo json_encode(
    $respuesta,
    JSON_UNESCAPED_UNICODE
);

// =====================================================
// CERRAR CONEXIÓN
// =====================================================

$conn->close();
