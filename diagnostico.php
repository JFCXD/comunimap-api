<?php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/conexion.php';

$result = $conn->query("
    SELECT 
        DATABASE() AS base_actual,
        @@hostname AS servidor_mysql,
        @@port AS puerto_mysql,
        (SELECT COUNT(*) FROM usuarios) AS total_usuarios
");

$data = $result->fetch_assoc();

echo json_encode([
    'success' => true,
    'diagnostico' => $data,
    'env' => [
        'MYSQLHOST' => getenv('MYSQLHOST'),
        'MYSQLPORT' => getenv('MYSQLPORT'),
        'MYSQLDATABASE' => getenv('MYSQLDATABASE'),
        'MYSQLUSER' => getenv('MYSQLUSER')
    ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
