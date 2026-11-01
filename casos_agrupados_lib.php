<?php

/*
|--------------------------------------------------------------------------
| COMUNIMAP - CÁLCULO DE CASOS AGRUPADOS
|--------------------------------------------------------------------------
|
| Este archivo contiene la lógica que utilizará la API
| para identificar problemas comunitarios coincidentes.
|
| No es un endpoint público.
| No envía respuestas JSON directamente.
| No modifica la base de datos.
|
*/

function cmEstaSolucionado(?string $estado): bool
{
    $estado = mb_strtolower(trim($estado ?? ''));

    return in_array(
        $estado,
        ['solucionado', 'resuelto'],
        true
    );
}

/*
|--------------------------------------------------------------------------
| CALCULAR DISTANCIA EN METROS
|--------------------------------------------------------------------------
*/
function cmDistanciaMetros(
    float $lat1,
    float $lon1,
    float $lat2,
    float $lon2
): float {

    $radioTierra = 6371000;

    $difLat = deg2rad($lat2 - $lat1);
    $difLon = deg2rad($lon2 - $lon1);

    $lat1Rad = deg2rad($lat1);
    $lat2Rad = deg2rad($lat2);

    $a =
        sin($difLat / 2) ** 2
        +
        cos($lat1Rad)
        * cos($lat2Rad)
        * sin($difLon / 2) ** 2;

    $a = min(1, max(0, $a));

    return $radioTierra * 2 * atan2(
        sqrt($a),
        sqrt(1 - $a)
    );
}

/*
|--------------------------------------------------------------------------
| COMPROBAR SI DOS REPORTES COINCIDEN
|--------------------------------------------------------------------------
*/
function cmSonCoincidentes(
    array $primero,
    array $segundo
): bool {

    if (
        cmEstaSolucionado($primero['estado']) ||
        cmEstaSolucionado($segundo['estado'])
    ) {
        return false;
    }

    if (
        (int) $primero['idCategoria'] !==
        (int) $segundo['idCategoria']
    ) {
        return false;
    }

    if (
        $primero['latitud'] === null ||
        $primero['longitud'] === null ||
        $segundo['latitud'] === null ||
        $segundo['longitud'] === null
    ) {
        return false;
    }

    $distancia = cmDistanciaMetros(
        (float) $primero['latitud'],
        (float) $primero['longitud'],
        (float) $segundo['latitud'],
        (float) $segundo['longitud']
    );

    return $distancia <= 5;
}

/*
|--------------------------------------------------------------------------
| CONSULTAR REPORTES
|--------------------------------------------------------------------------
|
| Devuelve los campos necesarios para agruparlos.
|
*/
function cmConsultarReportes(mysqli $conn): array
{
    $sql = "
        SELECT
            r.idReporte,
            r.titulo,
            r.estado,
            r.idUsuario,
            r.idCategoria,
            r.latitud,
            r.longitud,
            r.direccion,
            c.nombre AS categoria,
            u.nombre AS usuario
        FROM reportes r
        INNER JOIN categorias c
            ON r.idCategoria = c.idCategoria
        INNER JOIN usuarios u
            ON r.idUsuario = u.idUsuario
        ORDER BY r.idReporte DESC
    ";

    $resultado = $conn->query($sql);

    if (!$resultado) {
        throw new RuntimeException(
            'No se pudieron consultar los reportes'
        );
    }

    $reportes = [];

    while ($fila = $resultado->fetch_assoc()) {
        $reportes[] = $fila;
    }

    $resultado->free();

    return $reportes;
}

/*
|--------------------------------------------------------------------------
| AGRUPAR CASOS
|--------------------------------------------------------------------------
|
| Mantenemos el criterio utilizado en Laravel:
|
| - Un reporte representativo como centro.
| - Todos los integrantes a máximo 5 metros.
| - Misma categoría.
| - Solo reportes activos.
| - Usuarios diferentes cuentan una sola vez.
| - Cada reporte pertenece a un solo grupo.
| - Se elige primero el grupo disponible con más vecinos.
|
*/
function cmAgruparCasos(array $todosReportes): array
{
    $activos = [];

    foreach ($todosReportes as $reporte) {

        if (cmEstaSolucionado($reporte['estado'])) {
            continue;
        }

        if (
            $reporte['latitud'] === null ||
            $reporte['longitud'] === null
        ) {
            continue;
        }

        $activos[] = $reporte;
    }

    $grupos = [];
    $utilizados = [];

    while (true) {

        $mejorGrupo = null;
        $mejorCantidad = 0;

        foreach ($activos as $centro) {

            $idCentro = (int) $centro['idReporte'];

            if (isset($utilizados[$idCentro])) {
                continue;
            }

            $integrantes = [];
            $usuarios = [];

            foreach ($activos as $otro) {

                $idOtro = (int) $otro['idReporte'];

                if (isset($utilizados[$idOtro])) {
                    continue;
                }

                if (cmSonCoincidentes($centro, $otro)) {

                    $integrantes[] = $otro;

                    $usuarios[
                        (string) $otro['idUsuario']
                    ] = $otro['usuario'];
                }
            }

            $cantidad = count($usuarios);

            if ($cantidad > $mejorCantidad) {

                $mejorCantidad = $cantidad;

                $mejorGrupo = [
                    'centro' => $centro,
                    'integrantes' => $integrantes,
                    'usuarios' => $usuarios
                ];
            }
        }

        // Los individuales no forman grupos especiales.
        if (
            $mejorCantidad < 2 ||
            $mejorGrupo === null
        ) {
            break;
        }

        $centro = $mejorGrupo['centro'];
        $integrantes = $mejorGrupo['integrantes'];

        $idsReportes = [];

        foreach ($integrantes as $integrante) {

            $idReporte = (int) $integrante['idReporte'];

            $utilizados[$idReporte] = true;

            $idsReportes[] = $idReporte;
        }

        $cantidadVecinos = $mejorCantidad;

        if ($cantidadVecinos >= 5) {

            $prioridad = 'Alta';

        } elseif ($cantidadVecinos >= 3) {

            $prioridad = 'Media';

        } else {

            $prioridad = 'Baja';
        }

        $grupos[] = [
            'idReporte' => (int) $centro['idReporte'],
            'titulo' => $centro['titulo'],
            'categoria' => $centro['categoria'],
            'idCategoria' => (int) $centro['idCategoria'],
            'direccion' => $centro['direccion'],
            'cantidadVecinos' => $cantidadVecinos,
            'cantidadReportes' => count($integrantes),
            'prioridad' => $prioridad,
            'usuarios' => array_values(
                $mejorGrupo['usuarios']
            ),
            'idsReportes' => $idsReportes
        ];
    }

    return $grupos;
}

/*
|--------------------------------------------------------------------------
| OBTENER TODOS LOS CASOS ORDENADOS POR PRIORIDAD
|--------------------------------------------------------------------------
*/
function cmObtenerCasosAgrupados(mysqli $conn): array
{
    $reportes = cmConsultarReportes($conn);

    $grupos = cmAgruparCasos($reportes);

    $resultado = [
        'alta' => [],
        'media' => [],
        'baja' => []
    ];

    foreach ($grupos as $grupo) {

        if ($grupo['prioridad'] === 'Alta') {

            $resultado['alta'][] = $grupo;

        } elseif ($grupo['prioridad'] === 'Media') {

            $resultado['media'][] = $grupo;

        } else {

            $resultado['baja'][] = $grupo;
        }
    }

    foreach ($resultado as &$lista) {

        usort($lista, function ($a, $b) {

            return $b['cantidadVecinos']
                <=> $a['cantidadVecinos'];
        });
    }

    unset($lista);

    return $resultado;
}
