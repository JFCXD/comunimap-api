<?php

header('Content-Type: application/json; charset=utf-8');

require_once 'conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Método no permitido'
    ]);

    exit;
}

$datos = json_decode(file_get_contents('php://input'), true);

if (
    !isset($datos['idUsuario']) ||
    !isset($datos['token'])
) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Faltan datos requeridos'
    ]);

    exit;
}

$idUsuario = intval($datos['idUsuario']);
$token = trim($datos['token']);

if ($idUsuario <= 0 || $token === '') {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Datos inválidos'
    ]);

    exit;
}

try {

    // Verificar que el usuario exista.
    $stmtUsuario = $conn->prepare("
        SELECT idUsuario
        FROM usuarios
        WHERE idUsuario = ?
        LIMIT 1
    ");

    $stmtUsuario->bind_param("i", $idUsuario);
    $stmtUsuario->execute();

    $resultadoUsuario = $stmtUsuario->get_result();

    if ($resultadoUsuario->num_rows === 0) {
        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Usuario no encontrado'
        ]);

        exit;
    }

    // Buscar si el token ya existe.
    $stmtBuscar = $conn->prepare("
        SELECT idDispositivo, idUsuario
        FROM dispositivos_fcm
        WHERE token = ?
        LIMIT 1
    ");

    $stmtBuscar->bind_param("s", $token);
    $stmtBuscar->execute();

    $resultado = $stmtBuscar->get_result();

    if ($resultado->num_rows > 0) {

        $dispositivo = $resultado->fetch_assoc();

        // Si el token ya existe, actualizar el usuario asociado.
        $stmtActualizar = $conn->prepare("
            UPDATE dispositivos_fcm
            SET idUsuario = ?,
                fechaActualizacion = CURRENT_TIMESTAMP
            WHERE token = ?
        ");

        $stmtActualizar->bind_param("is", $idUsuario, $token);
        $stmtActualizar->execute();

        echo json_encode([
            'success' => true,
            'message' => 'Token FCM actualizado',
            'idUsuario' => $idUsuario
        ]);

        exit;
    }

    // Si el token no existe, insertarlo.
    $stmtInsertar = $conn->prepare("
        INSERT INTO dispositivos_fcm (
            idUsuario,
            token
        )
        VALUES (?, ?)
    ");

    $stmtInsertar->bind_param("is", $idUsuario, $token);
    $stmtInsertar->execute();

    echo json_encode([
        'success' => true,
        'message' => 'Token FCM registrado',
        'idUsuario' => $idUsuario
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor'
    ]);
}
