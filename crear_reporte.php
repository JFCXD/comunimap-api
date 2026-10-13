<?php

// =====================================================
// API - CREAR REPORTE
// =====================================================

header('Content-Type: application/json; charset=utf-8');

// Solo permitir POST
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
// VALIDAR JSON
// =====================================================

if (!is_array($datos)) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'JSON inválido'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// =====================================================
// VALIDAR CAMPOS OBLIGATORIOS
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
// DATOS DEL REPORTE
// =====================================================

$titulo = trim($datos['titulo']);
$descripcion = trim($datos['descripcion']);
$ubicacion = trim($datos['ubicacion']);

$direccion = $datos['direccion'] ?? null;
$imagen = $datos['imagen'] ?? null;

$estado = 'Pendiente';

$idUsuario = (int) $datos['idUsuario'];
$idCategoria = (int) $datos['idCategoria'];

// =====================================================
// INICIAR TRANSACCIÓN
// =====================================================

$conn->begin_transaction();

try {

    // =====================================================
    // CREAR REPORTE
    // =====================================================

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

    if (!$stmt) {
        throw new Exception('No se pudo preparar el registro del reporte');
    }

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

    if (!$stmt->execute()) {
        throw new Exception('No se pudo registrar el reporte');
    }

    // ID del reporte recién creado
    $idReporteNuevo = $conn->insert_id;

    // =====================================================
    // BUSCAR ADMINISTRADORES ACTIVOS
    // =====================================================

    $stmtAdmins = $conn->prepare("
        SELECT idUsuario
        FROM usuarios
        WHERE LOWER(TRIM(rol)) = 'administrador'
        AND LOWER(TRIM(estado)) = 'activo'
    ");

    if (!$stmtAdmins) {
        throw new Exception('No se pudo consultar administradores');
    }

    $stmtAdmins->execute();

    $resultadoAdmins = $stmtAdmins->get_result();

    // =====================================================
    // PREPARAR NOTIFICACIÓN
    // =====================================================

    $stmtNotificacion = $conn->prepare("
        INSERT INTO notificaciones
        (
            idUsuario,
            idReporte,
            titulo,
            mensaje,
            tipo,
            leida
        )
        VALUES (?, ?, ?, ?, ?, 0)
    ");

    if (!$stmtNotificacion) {
        throw new Exception('No se pudo preparar la notificación');
    }

    $tituloNotificacion = 'Nuevo reporte';

    $mensajeNotificacion =
        'Se ha registrado un nuevo reporte en ComuniMap.';

    $tipoNotificacion = 'NUEVO_REPORTE';

    // =====================================================
    // CREAR UNA NOTIFICACIÓN PARA CADA ADMINISTRADOR
    // =====================================================

    while ($admin = $resultadoAdmins->fetch_assoc()) {

        $idAdministrador = (int) $admin['idUsuario'];

        $stmtNotificacion->bind_param(
            'iisss',
            $idAdministrador,
            $idReporteNuevo,
            $tituloNotificacion,
            $mensajeNotificacion,
            $tipoNotificacion
        );

        if (!$stmtNotificacion->execute()) {
            throw new Exception(
                'No se pudo crear una notificación'
            );
        }
    }

    // =====================================================
    // CONFIRMAR TODO
    // =====================================================

    $conn->commit();

    // =====================================================
    // RESPUESTA
    // =====================================================

    echo json_encode([
        'success' => true,
        'message' => 'Reporte registrado correctamente',
        'idReporte' => $idReporteNuevo
    ], JSON_UNESCAPED_UNICODE);

    // =====================================================
    // CERRAR RECURSOS
    // =====================================================

    $stmtNotificacion->close();
    $stmtAdmins->close();
    $stmt->close();

} catch (Throwable $e) {

    // Revertir todo si falla reporte o notificación
    $conn->rollback();

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Error al registrar el reporte'
    ], JSON_UNESCAPED_UNICODE);
}

$conn->close();
