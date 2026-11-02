<?php
/**
 * Comprueba si una latitud/longitud está dentro del perímetro de la OTB.
 * El archivo GeoJSON usa coordenadas en orden [longitud, latitud].
 */
function puntoDentroOTB(float $latitud, float $longitud): bool
{
    if (!is_finite($latitud) || !is_finite($longitud)
        || $latitud < -90 || $latitud > 90
        || $longitud < -180 || $longitud > 180) {
        return false;
    }

    $ruta = __DIR__ . '/otb_limite_para_comunimap.geojson';
    $contenido = @file_get_contents($ruta);
    if ($contenido === false) {
        return false; // Fallar cerrado si el límite no está disponible.
    }

    $geojson = json_decode($contenido, true);
    $anillo = $geojson['features'][0]['geometry']['coordinates'][0] ?? null;
    if (!is_array($anillo) || count($anillo) < 4) {
        return false;
    }

    $x = $longitud;
    $y = $latitud;
    $dentro = false;
    $n = count($anillo) - 1; // GeoJSON cierra el anillo repitiendo el inicio.

    for ($i = 0; $i < $n; $i++) {
        if (!is_array($anillo[$i]) || !is_array($anillo[$i + 1])
            || count($anillo[$i]) < 2 || count($anillo[$i + 1]) < 2) {
            return false;
        }

        [$x1, $y1] = array_map('floatval', array_slice($anillo[$i], 0, 2));
        [$x2, $y2] = array_map('floatval', array_slice($anillo[$i + 1], 0, 2));

        // Consideramos válido el punto que cae exactamente en el borde.
        $cruz = ($x - $x1) * ($y2 - $y1) - ($y - $y1) * ($x2 - $x1);
        if (abs($cruz) <= 1e-11
            && $x >= min($x1, $x2) - 1e-10 && $x <= max($x1, $x2) + 1e-10
            && $y >= min($y1, $y2) - 1e-10 && $y <= max($y1, $y2) + 1e-10) {
            return true;
        }

        if (($y1 > $y) !== ($y2 > $y)) {
            $cruceX = $x1 + ($y - $y1) * ($x2 - $x1) / ($y2 - $y1);
            if ($x < $cruceX) {
                $dentro = !$dentro;
            }
        }
    }

    return $dentro;
}
