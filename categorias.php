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
    // CONSULTAR TODAS LAS CATEGORÍAS
    // ======================================

    $sql = "
        SELECT
            idCategoria,
            nombre,
            descripcion
        FROM categorias
        ORDER BY nombre ASC
    ";

    $consulta = $conexion->prepare($sql);

    $consulta->execute();

    // ======================================
    // OBTENER RESULTADOS
    // ======================================

    $categorias = $consulta->fetchAll(PDO::FETCH_ASSOC);

    // ======================================
    // ENVIAR JSON A ANDROID
    // ======================================

    echo json_encode(
        $categorias,
        JSON_UNESCAPED_UNICODE
    );

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "error" => "Error al consultar categorías"
    ]);

}