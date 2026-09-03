<?php

header("Content-Type: application/json; charset=UTF-8");

require_once "conexion.php";

try {

    $sql = "SELECT 
                idUsuario,
                nombre,
                email,
                telefono,
                rol,
                estado
            FROM usuarios";

    $consulta = $conexion->prepare($sql);
    $consulta->execute();

    $usuarios = $consulta->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($usuarios);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "error" => "Error al consultar usuarios"
    ]);
}