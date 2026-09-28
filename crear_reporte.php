<?php

// =====================================================
// API - CREAR REPORTE
// =====================================================

// Indicamos que la respuesta será JSON
header('Content-Type: application/json; charset=utf-8');

// Solo permitimos peticiones POST
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

// Leemos los datos JSON enviados desde Android
$datos = json_decode(file_get_contents('php://input'), true);

// =====================================================
// VALIDACIÓN DE DATOS
// =====================================================

if (
    empty($datos['titulo']) ||
    empty($datos['descripcion']) ||
    empty($datos['ubicacion']) ||
    empty($datos['idUsuario']) ||
    empty($datos['idCategoria'])
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

$titulo = trim($datos['titulo']);
$descripcion = trim($datos['descripcion']);
$ubicacion = trim($datos['ubicacion']);

// Datos opcionales
$direccion = $datos['direccion'] ?? null;
$imagen = $datos['imagen'] ?? null;

// Estado inicial del reporte
$estado = 'Pendiente';

// Convertimos los IDs a números enteros
$idUsuario = (int) $datos['idUsuario'];
$idCategoria = (int) $datos['idCategoria'];

// =====================================================
// INSERTAR REPORTE
// =====================================================

// Preparamos la consulta para evitar SQL Injection
$stmt = $conn->prepare("
    INSERT INTO reportes
    (
        titulo,
        descripcion,
        ubicacion,
        direccion,
        estado,
        idUsuario,
        idCategoria,
        imagen
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");

// Indicamos el tipo de cada dato
$stmt->bind_param(
    'sssssiis',
    $titulo,
    $descripcion,
    $ubicacion,
    $direccion,
    $estado,
    $idUsuario,
    $idCategoria,
    $imagen
);

// Ejecutamos la consulta
if (!$stmt->execute()) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Error al registrar reporte'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// =====================================================
// RESPUESTA EXITOSA
// =====================================================

echo json_encode([
    'success' => true,
    'message' => 'Reporte registrado correctamente',
    'idReporte' => $stmt->insert_id
], JSON_UNESCAPED_UNICODE);

// Cerramos recursos
$stmt->close();
$conn->close();
