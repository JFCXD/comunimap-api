<?php

$host = getenv("DB_HOST");
$db = getenv("DB_DATABASE");
$user = getenv("DB_USERNAME");
$password = getenv("DB_PASSWORD");


try {
    $conexion = new PDO(
        "mysql:host=$host;dbname=$db;charset=utf8mb4",
        $user,
        $password
    );

    $conexion->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "error" => "Error de conexión a la base de datos"
    ]);

    exit;
}