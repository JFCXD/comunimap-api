<?php

// ==========================================
// RESPUESTA EN FORMATO JSON
// ==========================================

header("Content-Type: application/json; charset=UTF-8");

// ==========================================
// CONEXIÓN A LA BASE DE DATOS
// ==========================================

require_once "conexion.php";

try {

    // ======================================
    // CONSULTAR TODOS LOS REPORTES
    // ======================================

    $sql = "
        SELECT
            r.idReporte,
            r.titulo,
            r.descripcion,
            r.ubicacion,
            r.direccion,
            r.estado,
            r.imagen,

            r.idUsuario,
            u.nombre AS usuario,

            r.idCategoria,
            c.nombre AS categoria

        FROM reportes r

        INNER JOIN usuarios u
            ON r.idUsuario = u.idUsuario

        INNER JOIN categorias c
            ON r.idCategoria = c.idCategoria

        ORDER BY r.idReporte DESC
    ";

    $consulta = $conexion->prepare($sql);

    $consulta->execute();

    // ======================================
    // OBTENER RESULTADOS
    // ======================================

    $reportes = $consulta->fetchAll(PDO::FETCH_ASSOC);

    // ======================================
    // ENVIAR JSON A ANDROID
    // ======================================

    echo json_encode(
        $reportes,
        JSON_UNESCAPED_UNICODE
    );

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "error" => "Error al consultar reportes"
    ]);

}