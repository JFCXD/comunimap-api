<?php

// ==========================================
// RESPUESTA JSON
// ==========================================

header("Content-Type: application/json; charset=UTF-8");

// Solo permitimos solicitudes POST
if ($_SERVER["REQUEST_METHOD"] !== "POST") {

    http_response_code(405);

    echo json_encode([
        "success" => false,
        "mensaje" => "Método no permitido"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// ==========================================
// CONEXIÓN A MYSQL
// ==========================================

require_once "conexion.php";

// ==========================================
// RECIBIR JSON ENVIADO POR ANDROID
// ==========================================

$datos = json_decode(
    file_get_contents("php://input"),
    true
);

// ==========================================
// VALIDAR JSON
// ==========================================

if (!is_array($datos)) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "mensaje" => "JSON inválido"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// ==========================================
// VALIDAR DATOS OBLIGATORIOS
// ==========================================

if (
    empty($datos["titulo"]) ||
    empty($datos["descripcion"]) ||
    empty($datos["ubicacion"]) ||
    empty($datos["idUsuario"]) ||
    empty($datos["idCategoria"])
) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "mensaje" => "Faltan datos obligatorios"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {

    // ==========================================
    // INICIAR TRANSACCIÓN
    // ==========================================

    $conexion->beginTransaction();

    // ==========================================
    // REGISTRAR REPORTE
    // ==========================================

    $sql = "
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
        VALUES
        (
            :titulo,
            :descripcion,
            :ubicacion,
            :direccion,
            :estado,
            :idUsuario,
            :idCategoria,
            :imagen
        )
    ";

    $consulta = $conexion->prepare($sql);

    $consulta->execute([

        ":titulo" =>
            trim($datos["titulo"]),

        ":descripcion" =>
            trim($datos["descripcion"]),

        ":ubicacion" =>
            trim($datos["ubicacion"]),

        ":direccion" =>
            $datos["direccion"] ?? null,

        // Todo reporte nuevo comienza como Pendiente
        ":estado" =>
            "Pendiente",

        ":idUsuario" =>
            (int) $datos["idUsuario"],

        ":idCategoria" =>
            (int) $datos["idCategoria"],

        ":imagen" =>
            $datos["imagen"] ?? null
    ]);

    // ==========================================
    // OBTENER ID DEL REPORTE CREADO
    // ==========================================

    $idReporteNuevo = (int) $conexion->lastInsertId();

    // ==========================================
    // BUSCAR ADMINISTRADORES ACTIVOS
    // ==========================================

    $sqlAdministradores = "
        SELECT idUsuario
        FROM usuarios
        WHERE LOWER(TRIM(rol)) = 'administrador'
        AND LOWER(TRIM(estado)) = 'activo'
    ";

    $consultaAdministradores =
        $conexion->prepare($sqlAdministradores);

    $consultaAdministradores->execute();

    $administradores =
        $consultaAdministradores->fetchAll(PDO::FETCH_ASSOC);

    // ==========================================
    // CREAR NOTIFICACIÓN PARA CADA ADMINISTRADOR
    // ==========================================

    if (!empty($administradores)) {

        $sqlNotificacion = "
            INSERT INTO notificaciones
            (
                idUsuario,
                idReporte,
                titulo,
                mensaje,
                tipo,
                leida
            )
            VALUES
            (
                :idUsuario,
                :idReporte,
                :titulo,
                :mensaje,
                :tipo,
                0
            )
        ";

        $consultaNotificacion =
            $conexion->prepare($sqlNotificacion);

        foreach ($administradores as $administrador) {

            $consultaNotificacion->execute([

                ":idUsuario" =>
                    (int) $administrador["idUsuario"],

                ":idReporte" =>
                    $idReporteNuevo,

                ":titulo" =>
                    "Nuevo reporte",

                ":mensaje" =>
                    "Se ha registrado un nuevo reporte en ComuniMap.",

                ":tipo" =>
                    "NUEVO_REPORTE"
            ]);
        }
    }

    // ==========================================
    // CONFIRMAR TRANSACCIÓN
    // ==========================================

    $conexion->commit();

    // ==========================================
    // RESPUESTA CORRECTA
    // ==========================================

    echo json_encode([
        "success" => true,
        "mensaje" => "Reporte registrado correctamente",
        "idReporte" => $idReporteNuevo
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {

    // ==========================================
    // CANCELAR CAMBIOS SI ALGO FALLA
    // ==========================================

    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "mensaje" => "Error al registrar el reporte"
    ], JSON_UNESCAPED_UNICODE);
}
