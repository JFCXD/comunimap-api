
<?php

/*
|--------------------------------------------------------------------------
| COMUNIMAP - CONSULTA DE CASOS AGRUPADOS
|--------------------------------------------------------------------------
|
| Endpoint de solo lectura.
| Devuelve los casos de prioridad Alta, Media y Baja.
| No modifica reportes ni expone nombres de vecinos.
|
*/

// Configurar encabezados de respuesta.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

// Permitir únicamente solicitudes GET.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {

    header('Allow: GET');

    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Método no permitido'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// Cargar conexión y funciones de agrupación.
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/casos_agrupados_lib.php';

try {

    // Consultar los casos agrupados.
    $grupos = cmObtenerCasosAgrupados($conn);

    // Estructura de respuesta.
    $casos = [
        'alta' => [],
        'media' => [],
        'baja' => []
    ];

    foreach (['alta', 'media', 'baja'] as $nivel) {

        foreach ($grupos[$nivel] as $grupo) {

            $casos[$nivel][] = [
                'idReporte' => (int) $grupo['idReporte'],
                'titulo' => $grupo['titulo'],
                'categoria' => $grupo['categoria'],
                'idCategoria' => (int) $grupo['idCategoria'],
                'direccion' => $grupo['direccion'],
                'cantidadVecinos' => (int) $grupo['cantidadVecinos'],
                'cantidadReportes' => (int) $grupo['cantidadReportes'],
                'prioridad' => $grupo['prioridad']
            ];
        }
    }

    // Contar grupos de cada prioridad.
    $totalAlta = count($casos['alta']);
    $totalMedia = count($casos['media']);
    $totalBaja = count($casos['baja']);

    $total = $totalAlta + $totalMedia + $totalBaja;

    // Responder con JSON válido.
    http_response_code(200);

    echo json_encode([
        'success' => true,
        'message' => 'Casos agrupados obtenidos correctamente',
        'total' => $total,
        'totales' => [
            'alta' => $totalAlta,
            'media' => $totalMedia,
            'baja' => $totalBaja
        ],
        'casos' => $casos
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {

    error_log(
        'Error en casos_agrupados.php: ' .
        $e->getMessage()
    );

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'No se pudieron obtener los casos agrupados'
    ], JSON_UNESCAPED_UNICODE);
}

// Cerrar conexión.
$conn->close();
