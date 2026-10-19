<?php

// =====================================================
// API - CREAR REPORTE
// =====================================================

header('Content-Type: application/json; charset=utf-8');

// =====================================================
// VALIDAR MÉTODO
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
// CONEXIÓN
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
// DATOS PRINCIPALES
// =====================================================

$titulo = trim($datos['titulo']);
$descripcion = trim($datos['descripcion']);
$ubicacion = trim($datos['ubicacion']);

$direccion = $datos['direccion'] ?? null;
$imagen = $datos['imagen'] ?? null;

// Estado inicial
$estado = 'Pendiente';

// La prioridad será calculada posteriormente.
// Por ahora queda NULL.
$prioridad = null;

$idUsuario = (int) $datos['idUsuario'];
$idCategoria = (int) $datos['idCategoria'];

// =====================================================
// SEPARAR LATITUD Y LONGITUD
// =====================================================

$latitud = null;
$longitud = null;

// Android actualmente envía:
// -17.437995,-66.146491
if (
    !empty($ubicacion) &&
    strpos($ubicacion, ',') !== false
) {

    $coordenadas = explode(',', $ubicacion);

    if (count($coordenadas) >= 2) {

        $latitudTemporal = trim($coordenadas[0]);
        $longitudTemporal = trim($coordenadas[1]);

        if (
            is_numeric($latitudTemporal) &&
            is_numeric($longitudTemporal)
        ) {

            $latitud = (float) $latitudTemporal;
            $longitud = (float) $longitudTemporal;
        }
    }
}

// =====================================================
// VALIDAR COORDENADAS
// =====================================================

if ($latitud === null || $longitud === null) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'La ubicación del reporte no contiene coordenadas válidas.'
    ], JSON_UNESCAPED_UNICODE);

    $conn->close();

    exit;
}

// =====================================================
// INSERTAR REPORTE
// =====================================================

$stmt = $conn->prepare("
    INSERT INTO reportes
    (
        titulo,
        descripcion,
        ubicacion,
        latitud,
        longitud,
        direccion,
        estado,
        prioridad,
        idUsuario,
        idCategoria,
        imagen
    )
    VALUES
    (
        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
    )
");

// Tipos:
// s titulo
// s descripcion
// s ubicacion
// d latitud
// d longitud
// s direccion
// s estado
// s prioridad
// i idUsuario
// i idCategoria
// s imagen

$stmt->bind_param(
    'sssddsssiis',
    $titulo,
    $descripcion,
    $ubicacion,
    $latitud,
    $longitud,
    $direccion,
    $estado,
    $prioridad,
    $idUsuario,
    $idCategoria,
    $imagen
);

// =====================================================
// EJECUTAR
// =====================================================

if (!$stmt->execute()) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Error al registrar reporte'
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
    'message' => 'Reporte registrado correctamente',
    'idReporte' => $stmt->insert_id
], JSON_UNESCAPED_UNICODE);

// =====================================================
// CERRAR
// =====================================================

$stmt->close();
$conn->close();
