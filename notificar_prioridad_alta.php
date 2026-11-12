<?php
/**
 * Notifica a los administradores cuando un nuevo reporte hace que un
 * caso alcance por primera vez 5 vecinos DISTINTOS en un radio de 5 m,
 * con la misma categoría y reportes no solucionados.
 *
 * No cambia los estados ni las prioridades de los reportes. Reutiliza
 * notificaciones internas + Firebase, y no afecta el registro si falla.
 * Se llama después de INSERT en crear_reporte.php.
 */

function distanciaComuniMapMetros(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return 6371000 * 2 * atan2(sqrt(max(0, min(1, $a))), sqrt(max(0, 1 - $a)));
}

function notificarPrioridadAlta(
    mysqli $conn,
    int $idReporteNuevo,
    int $idCategoria,
    float $latitud,
    float $longitud
): void {
    // La búsqueda espacial abarca cualquier reporte a 10 m del nuevo
    // (un centro hasta 5 m y sus vecinos hasta otros 5 m).
    $deltaLat = 10 / 111000;
    $deltaLon = 10 / (111000 * max(0.1, abs(cos(deg2rad($latitud)))));
    $minLat = $latitud - $deltaLat;
    $maxLat = $latitud + $deltaLat;
    $minLon = $longitud - $deltaLon;
    $maxLon = $longitud + $deltaLon;

    $stmt = $conn->prepare("
        SELECT idReporte, idUsuario, titulo, latitud, longitud
        FROM reportes
        WHERE idCategoria = ?
          AND LOWER(TRIM(estado)) NOT IN ('solucionado', 'resuelto')
          AND latitud BETWEEN ? AND ?
          AND longitud BETWEEN ? AND ?
    ");
    if (!$stmt) {
        throw new RuntimeException('No se pudo consultar la coincidencia de reportes');
    }
    $stmt->bind_param('idddd', $idCategoria, $minLat, $maxLat, $minLon, $maxLon);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Falló la consulta de coincidencias');
    }
    $resultado = $stmt->get_result();
    $reportes = [];
    while ($fila = $resultado->fetch_assoc()) {
        $fila['idReporte'] = (int) $fila['idReporte'];
        $fila['idUsuario'] = (int) $fila['idUsuario'];
        $fila['latitud'] = (float) $fila['latitud'];
        $fila['longitud'] = (float) $fila['longitud'];
        $reportes[] = $fila;
    }
    $stmt->close();

    // Tomar los centros que incluyen al nuevo reporte. Para cada centro,
    // comprobar que el quinto vecino DISTINTO sea precisamente el nuevo.
    $caso = null;
    foreach ($reportes as $centro) {
        if (distanciaComuniMapMetros($latitud, $longitud, $centro['latitud'], $centro['longitud']) > 5) {
            continue;
        }

        $usuariosAhora = [];
        $usuariosAntes = [];
        $idsMiembros = [];
        foreach ($reportes as $otro) {
            if (distanciaComuniMapMetros($centro['latitud'], $centro['longitud'], $otro['latitud'], $otro['longitud']) > 5) {
                continue;
            }
            $idsMiembros[] = $otro['idReporte'];
            $usuariosAhora[$otro['idUsuario']] = true;
            if ($otro['idReporte'] !== $idReporteNuevo) {
                $usuariosAntes[$otro['idUsuario']] = true;
            }
        }

        if (count($usuariosAntes) < 5 && count($usuariosAhora) >= 5) {
            $caso = [
                'idReporte' => $centro['idReporte'],
                'titulo' => (string) $centro['titulo'],
                'vecinos' => count($usuariosAhora),
                'miembros' => $idsMiembros,
            ];
            break;
        }
    }
    if ($caso === null) {
        return;
    }

    // No avisar de nuevo si este caso ya generó una alerta alta.
    // La comprobación por miembro también funciona si cambió el centro.
    $miembros = array_values(array_unique(array_map('intval', $caso['miembros'])));
    if (!$miembros) {
        return;
    }
    $marcadores = implode(',', array_fill(0, count($miembros), '?'));
    $existente = $conn->prepare("
        SELECT idNotificacion FROM notificaciones
        WHERE tipo = 'PRIORIDAD_ALTA' AND idReporte IN ($marcadores)
        LIMIT 1
    ");
    if (!$existente) {
        throw new RuntimeException('No se pudo verificar avisos previos');
    }
    $tipos = str_repeat('i', count($miembros));
    $existente->bind_param($tipos, ...$miembros);
    $existente->execute();
    $yaNotificado = (bool) $existente->get_result()->fetch_assoc();
    $existente->close();
    if ($yaNotificado) {
        return;
    }

    $admins = $conn->query("SELECT idUsuario FROM usuarios WHERE rol = 'Administrador' AND estado = 'Activo'");
    if (!$admins) {
        throw new RuntimeException('No se pudieron obtener los administradores');
    }

    $titulo = 'Caso de prioridad alta';
    $mensaje = 'Atención: "' . $caso['titulo'] . '" alcanzó '
        . $caso['vecinos'] . ' vecinos diferentes y requiere atención prioritaria.';
    $mensaje = mb_substr($mensaje, 0, 255);
    $tipo = 'PRIORIDAD_ALTA';
    $idCaso = $caso['idReporte'];

    $insertar = $conn->prepare("
        INSERT INTO notificaciones (idUsuario, idReporte, titulo, mensaje, tipo, leida)
        VALUES (?, ?, ?, ?, ?, 0)
    ");
    $consultarTokens = $conn->prepare("SELECT DISTINCT token FROM dispositivos_fcm WHERE idUsuario = ?");
    if (!$insertar || !$consultarTokens) {
        throw new RuntimeException('No se pudieron preparar avisos de prioridad alta');
    }
    require_once __DIR__ . '/enviar_notificacion_push.php';

    while ($admin = $admins->fetch_assoc()) {
        $idAdmin = (int) $admin['idUsuario'];
        $insertar->bind_param('iisss', $idAdmin, $idCaso, $titulo, $mensaje, $tipo);
        if (!$insertar->execute()) {
            error_log('ComuniMap: no se guardó la alerta alta para el admin ' . $idAdmin);
            continue;
        }

        $consultarTokens->bind_param('i', $idAdmin);
        if (!$consultarTokens->execute()) {
            continue;
        }
        foreach ($consultarTokens->get_result()->fetch_all(MYSQLI_ASSOC) as $dispositivo) {
            $token = trim((string) ($dispositivo['token'] ?? ''));
            if ($token === '') {
                continue;
            }
            $envio = enviarNotificacionPush($token, $titulo, $mensaje, [
                'tipo' => $tipo,
                'idReporte' => $idCaso,
            ]);
            if (empty($envio['success'])) {
                error_log('ComuniMap: falló push de prioridad alta para el admin ' . $idAdmin);
            }
        }
    }

    $insertar->close();
    $consultarTokens->close();
}
