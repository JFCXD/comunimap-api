
<?php

/*
|--------------------------------------------------------------------------
| COMUNIMAP - CONSULTAR CASOS AGRUPADOS
|--------------------------------------------------------------------------
|
| Endpoint de solo lectura para Android.
|
| Devuelve:
| - Casos de prioridad Alta.
| - Casos de prioridad Media.
| - Casos de prioridad Baja.
| - Cantidad de vecinos distintos.
| - Cantidad de reportes agrupados.
| - Información general del problema.
|
| No devuelve nombres de vecinos ni identificadores
| internos de todos los integrantes del grupo.
|
| No cambia estados ni modifica la base de datos.
|
*/

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

// =====================================================
// VALIDAR MÉTODO HTTP
// =====================================================

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {

    header('Allow: GET');

    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Método no permitido'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// =====================================================
// CONECTAR CON MYSQL
// =====================================================

require_once __DIR__ . '/conexion.php';

// =====================================================
// IMPORTAR LÓGICA DE AGRUPACIÓN
// =====================================================

require_once __DIR__ . '/casos_agrupados_lib.php';

try {

    // Obtener los grupos calculados por la biblioteca.
    $grupos = cmObtenerCasosAgrupados($conn);

    // Preparar respuesta pública sin datos personales.
    $casos = [
        'alta' => [],
        'media' => [],
        'baja' => []
    ];

    foreach (['alta', 'media', 'baja'] as $nivel) {

        foreach ($grupos[$nivel] as $grupo) {

            $casos[$nivel][] = [
                'idReporte' =>
                    (int) $grupo['idReporte'],

                'titulo' =>
                    $grupo['titulo'],

                'categoria' =>
                    $grupo['categoria'],

                'idCategoria' =>
                    (int) $grupo['idCategoria'],

                'direccion' =>
                    $grupo['direccion'],

                'cantidadVecinos' =>
                    (int) $grupo['cantidadVecinos'],

                'cantidadReportes' =>
                    (int) $grupo['cantidadReportes'],

                'prioridad' =>
                    $grupo['prioridad']
            ];
        }
    }

    // =================================================
    // CONTAR CASOS POR PRIORIDAD
    // =================================================

    $totalAlta = count($casos['alta']);
    $totalMedia = count($casos['media']);
    $totalBaja = count($casos['baja']);

    $total = $totalAlta + $totalMedia + $totalBaja;

    // =================================================
    // DEVOLVER RESPUESTA
    // =================================================

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

    // No mostrar detalles internos de MySQL al cliente.
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

// =====================================================
// CERRAR CONEXIÓN
// =====================================================

$conn->close();
