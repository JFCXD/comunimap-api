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
    ]);

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
    ]);

    exit;
}

try {

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

        // Todo reporte nuevo comienza pendiente
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
    // RESPUESTA CORRECTA
    // ==========================================

    echo json_encode([
        "success" => true,
        "mensaje" => "Reporte registrado correctamente",
        "idReporte" => $conexion->lastInsertId()
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "mensaje" => "Error al registrar el reporte"
    ]);
}