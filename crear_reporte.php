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
// VALIDAR QUE LA UBICACIÓN PERTENEZCA A LA OTB
// =====================================================

require_once __DIR__ . '/validar_otb.php';

if (!puntoDentroOTB($latitud, $longitud)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'La ubicación debe estar dentro de los límites de la OTB.'
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

// AVISAR A TODOS LOS ADMINISTRADORES ACTIVOS (NO USAR IDs FIJOS)
$idReporteCreado = (int) $stmt->insert_id;
try {
    require_once __DIR__ . '/enviar_notificacion_push.php';
    $listaAdmins = $conn->query("SELECT idUsuario FROM usuarios WHERE rol = 'Administrador' AND estado = 'Activo'");
    if (!$listaAdmins) throw new Exception('Error consultando administradores');
    $avisoTitulo = 'Nuevo reporte';
    $avisoMensaje = substr('Nuevo reporte registrado: ' . $titulo, 0, 255);
    $avisoTipo = 'NUEVO_REPORTE';
    $insertAviso = $conn->prepare("INSERT INTO notificaciones (idUsuario,idReporte,titulo,mensaje,tipo,leida) VALUES (?,?,?,?,?,0)");
    $consultaTokens = $conn->prepare("SELECT token FROM dispositivos_fcm WHERE idUsuario = ?");
    if (!$insertAviso || !$consultaTokens) throw new Exception('Error preparando avisos');
    while ($admin = $listaAdmins->fetch_assoc()) {
        $idAdmin = (int) $admin['idUsuario'];
        if ($idAdmin === $idUsuario) continue;
        $insertAviso->bind_param('iisss', $idAdmin, $idReporteCreado, $avisoTitulo, $avisoMensaje, $avisoTipo);
        if (!$insertAviso->execute()) {
            error_log('ComuniMap: error guardando aviso para administrador ' . $idAdmin);
            continue;
        }
        $consultaTokens->bind_param('i', $idAdmin);
        if (!$consultaTokens->execute()) continue;
        $tokens = $consultaTokens->get_result();
        while ($fila = $tokens->fetch_assoc()) {
            if (empty($fila['token'])) continue;
            $push = enviarNotificacionPush($fila['token'], $avisoTitulo, $avisoMensaje, [
                'tipo' => $avisoTipo, 'idReporte' => $idReporteCreado
            ]);
            if (empty($push['success'])) error_log('ComuniMap: push falló para administrador ' . $idAdmin);
        }
    }
    $insertAviso->close();
    $consultaTokens->close();
} catch (Throwable $e) {
    error_log('ComuniMap: error al notificar nuevo reporte: ' . $e->getMessage());
}

// AVISO DE PRIORIDAD ALTA: un quinto vecino distinto reportó un problema
// de la misma categoría dentro de un radio de cinco metros.
// No modifica el reporte ni reemplaza el aviso de nuevo reporte.
try {
    require_once __DIR__ . '/notificar_prioridad_alta.php';
    notificarPrioridadAlta($conn, $idReporteCreado, $idCategoria, (float)$latitud, (float)$longitud);
} catch (Throwable $e) {
    // No fallar la creación del reporte si falla la notificación.
    error_log('ComuniMap: error al notificar prioridad alta: ' . $e->getMessage());
}

// =====================================================
// RESPUESTA
// =====================================================

echo json_encode([
    'success' => true,
    'message' => 'Reporte registrado correctamente',
    'idReporte' => $idReporteCreado
], JSON_UNESCAPED_UNICODE);

// =====================================================
// CERRAR
// =====================================================

$stmt->close();
$conn->close();
