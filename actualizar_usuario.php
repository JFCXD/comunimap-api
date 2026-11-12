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

// Solo actualizar datos nuevos cuando el cliente los envía: versiones anteriores
// de Android no deben borrar apellido y fecha previamente guardados.
$actualizarApellido = array_key_exists('apellido', $datos);
$actualizarNacimiento = array_key_exists('fechaNacimiento', $datos);
$apellido = $actualizarApellido && $datos['apellido'] !== null
    ? trim((string)$datos['apellido']) : null;
$fechaNacimiento = $actualizarNacimiento && $datos['fechaNacimiento'] !== null
    && $datos['fechaNacimiento'] !== ''
    ? trim((string)$datos['fechaNacimiento']) : null;

if (($actualizarApellido && $apellido !== null && mb_strlen($apellido) > 150)
    || ($actualizarNacimiento && $fechaNacimiento !== null && (
        !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $fechaNacimiento)
        || !($fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $fechaNacimiento))
        || $fecha->format('Y-m-d') !== $fechaNacimiento
        || $fecha > new DateTimeImmutable('today')
    ))) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Apellido o fecha de nacimiento inválidos'], JSON_UNESCAPED_UNICODE);
    $conn->close();
    exit;
}

// =====================================================
// ACTUALIZAR USUARIO
// =====================================================

$sql = "UPDATE usuarios SET nombre = ?, email = ?, telefono = ?, rol = ?, estado = ?";
$parametros = [$nombre, $email, $telefono, $rol, $estado];
$tipos = 'sssss';
if ($actualizarApellido) {
    $sql .= ", apellido = ?";
    $parametros[] = $apellido;
    $tipos .= 's';
}
if ($actualizarNacimiento) {
    $sql .= ", fechaNacimiento = ?";
    $parametros[] = $fechaNacimiento;
    $tipos .= 's';
}
$sql .= " WHERE idUsuario = ?";
$parametros[] = $idUsuario;
$tipos .= 'i';

$stmt = $conn->prepare($sql);
$stmt->bind_param($tipos, ...$parametros);

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
