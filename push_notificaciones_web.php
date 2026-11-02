<?php
/*
 * COMUNIMAP - PUSH DE NOTIFICACIONES ORIGINADAS EN LARAVEL
 *
 * Solo acepta IDs de notificaciones internas existentes.
 * No modifica reportes, no crea notificaciones y no expone
 * credenciales Firebase. Requiere una clave compartida
 * configurada en ambos servicios Railway.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

$clave = getenv('COMUNIMAP_WEB_PUSH_SECRET');
$recibida = $_SERVER['HTTP_X_COMUNIMAP_WEB_PUSH_SECRET'] ?? '';

if (!is_string($clave) || strlen($clave) < 32 ||
    !is_string($recibida) || !hash_equals($clave, $recibida)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
    exit;
}

$datos = json_decode(file_get_contents('php://input'), true);
$ids = $datos['idsNotificaciones'] ?? null;

if (!is_array($ids) || count($ids) < 1 || count($ids) > 50) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Lista inválida de notificaciones']);
    exit;
}

$idsValidos = [];
foreach ($ids as $id) {
    if (!is_int($id) || $id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Identificador inválido']);
        exit;
    }
    $idsValidos[$id] = true;
}

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/enviar_notificacion_push.php';

$enviados = 0;
$fallidos = 0;
$sinDispositivo = 0;
$procesados = 0;

try {
    $consulta = $conn->prepare("
        SELECT
            idNotificacion,
            idUsuario,
            idReporte,
            titulo,
            mensaje,
            tipo
        FROM notificaciones
        WHERE idNotificacion = ?
          AND tipo IN ('EN_PROCESO', 'SOLUCIONADO')
          AND fecha >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        LIMIT 1
    ");
    $tokens = $conn->prepare("
        SELECT DISTINCT token
        FROM dispositivos_fcm
        WHERE idUsuario = ?
    ");

    if (!$consulta || !$tokens) {
        throw new RuntimeException('No se pudieron preparar las consultas');
    }

    foreach (array_keys($idsValidos) as $idNotificacion) {
        $consulta->bind_param('i', $idNotificacion);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();

        if (!$fila) {
            continue;
        }

        $procesados++;
        $idUsuario = (int) $fila['idUsuario'];
        $tokens->bind_param('i', $idUsuario);
        $tokens->execute();
        $resultadoTokens = $tokens->get_result();

        $tieneDispositivos = false;
        while ($dispositivo = $resultadoTokens->fetch_assoc()) {
            $token = trim((string) ($dispositivo['token'] ?? ''));
            if ($token === '') {
                continue;
            }

            $tieneDispositivos = true;
            $resultado = enviarNotificacionPush(
                $token,
                (string) $fila['titulo'],
                (string) $fila['mensaje'],
                [
                    'tipo' => (string) $fila['tipo'],
                    'idReporte' => (int) $fila['idReporte']
                ]
            );

            if (!empty($resultado['success'])) {
                $enviados++;
            } else {
                $fallidos++;
                error_log(
                    'ComuniMap push web: fallo de Firebase para notificacion '
                    . $idNotificacion . ', http='
                    . (int) ($resultado['http_code'] ?? 0)
                );
            }
        }

        if (!$tieneDispositivos) {
            $sinDispositivo++;
        }
    }

    $consulta->close();
    $tokens->close();
} catch (Throwable $e) {
    error_log('ComuniMap push web: error interno: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'No se pudo procesar el envío push'
    ]);
    $conn->close();
    exit;
}

$conn->close();

echo json_encode([
    'success' => true,
    'notificacionesProcesadas' => $procesados,
    'enviosPushExitosos' => $enviados,
    'enviosPushFallidos' => $fallidos,
    'notificacionesSinDispositivo' => $sinDispositivo
], JSON_UNESCAPED_UNICODE);
