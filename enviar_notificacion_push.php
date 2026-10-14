<?php

/**
 * ============================================================
 * COMUNIMAP - ENVÍO DE NOTIFICACIONES PUSH CON FIREBASE
 * ============================================================
 *
 * Este archivo NO guarda notificaciones en MySQL.
 *
 * Su única responsabilidad es:
 *
 * 1. Leer la cuenta de servicio de Firebase desde Railway.
 * 2. Obtener un token temporal OAuth 2.0 de Google.
 * 3. Enviar una notificación mediante Firebase Cloud Messaging.
 *
 * Variable requerida en Railway:
 *
 * FIREBASE_SERVICE_ACCOUNT_B64
 *
 * IMPORTANTE:
 * Nunca colocar aquí directamente la clave privada de Firebase.
 */


/**
 * Convierte texto a Base64 URL Safe.
 */
function base64UrlEncode(string $datos): string
{
    return rtrim(
        strtr(
            base64_encode($datos),
            '+/',
            '-_'
        ),
        '='
    );
}


/**
 * Obtiene la configuración de la cuenta de servicio
 * almacenada como Base64 en Railway.
 */
function obtenerCuentaServicioFirebase(): array
{
    $credencialBase64 = getenv('FIREBASE_SERVICE_ACCOUNT_B64');

    if (!$credencialBase64) {
        throw new Exception(
            'No existe la variable FIREBASE_SERVICE_ACCOUNT_B64.'
        );
    }

    $json = base64_decode($credencialBase64, true);

    if ($json === false) {
        throw new Exception(
            'No se pudo decodificar la credencial de Firebase.'
        );
    }

    $cuentaServicio = json_decode($json, true);

    if (
        !is_array($cuentaServicio) ||
        empty($cuentaServicio['client_email']) ||
        empty($cuentaServicio['private_key']) ||
        empty($cuentaServicio['project_id'])
    ) {
        throw new Exception(
            'La credencial de Firebase no contiene los datos requeridos.'
        );
    }

    return $cuentaServicio;
}


/**
 * Genera un token temporal OAuth 2.0
 * para poder utilizar Firebase Cloud Messaging.
 */
function obtenerTokenAccesoFirebase(): string
{
    $cuentaServicio = obtenerCuentaServicioFirebase();

    $ahora = time();

    // Encabezado del JWT.
    $encabezado = [
        'alg' => 'RS256',
        'typ' => 'JWT'
    ];

    // Información requerida por Google.
    $carga = [
        'iss'   => $cuentaServicio['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $ahora,
        'exp'   => $ahora + 3600
    ];

    $encabezadoCodificado = base64UrlEncode(
        json_encode($encabezado)
    );

    $cargaCodificada = base64UrlEncode(
        json_encode($carga)
    );

    $contenidoFirma =
        $encabezadoCodificado . '.' . $cargaCodificada;

    // Firmar usando la clave privada de Firebase.
    $firma = '';

    $firmado = openssl_sign(
        $contenidoFirma,
        $firma,
        $cuentaServicio['private_key'],
        OPENSSL_ALGO_SHA256
    );

    if (!$firmado) {
        throw new Exception(
            'No se pudo firmar la solicitud para Firebase.'
        );
    }

    $jwt =
        $contenidoFirma .
        '.' .
        base64UrlEncode($firma);

    // Solicitar token temporal a Google.
    $curl = curl_init(
        'https://oauth2.googleapis.com/token'
    );

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded'
        ],
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' =>
                'urn:ietf:params:oauth:grant-type:jwt-bearer',

            'assertion' => $jwt
        ]),
        CURLOPT_TIMEOUT => 20
    ]);

    $respuesta = curl_exec($curl);

    if ($respuesta === false) {
        $error = curl_error($curl);

        curl_close($curl);

        throw new Exception(
            'Error al comunicarse con Google: ' . $error
        );
    }

    $codigoHttp = curl_getinfo(
        $curl,
        CURLINFO_HTTP_CODE
    );

    curl_close($curl);

    $datosRespuesta = json_decode(
        $respuesta,
        true
    );

    if (
        $codigoHttp < 200 ||
        $codigoHttp >= 300 ||
        empty($datosRespuesta['access_token'])
    ) {
        throw new Exception(
            'Google no entregó un token de acceso válido.'
        );
    }

    return $datosRespuesta['access_token'];
}


/**
 * Envía una notificación PUSH a un dispositivo Android.
 *
 * $tokenDispositivo:
 * Token FCM del teléfono.
 *
 * $titulo:
 * Título visible de la notificación.
 *
 * $mensaje:
 * Texto visible.
 *
 * $datos:
 * Información adicional opcional.
 */
function enviarNotificacionPush(
    string $tokenDispositivo,
    string $titulo,
    string $mensaje,
    array $datos = []
): array {

    if (trim($tokenDispositivo) === '') {
        return [
            'success' => false,
            'message' => 'Token FCM vacío.'
        ];
    }

    try {

        $cuentaServicio =
            obtenerCuentaServicioFirebase();

        $projectId =
            $cuentaServicio['project_id'];

        $tokenAcceso =
            obtenerTokenAccesoFirebase();

        /*
         * Firebase exige que todos los valores
         * del bloque "data" sean texto.
         */
        $datosFirebase = [];

        foreach ($datos as $clave => $valor) {
            $datosFirebase[(string)$clave] =
                (string)$valor;
        }

        $mensajeFirebase = [

            'message' => [

                'token' => $tokenDispositivo,

                // Contenido visible.
                'notification' => [
                    'title' => $titulo,
                    'body'  => $mensaje
                ],

                // Información adicional.
                'data' => $datosFirebase,

                // Configuración específica para Android.
                'android' => [

                    'priority' => 'high',

                    'notification' => [
                        'channel_id' =>
                            'comunimap_general'
                    ]
                ]
            ]
        ];

        $url =
            'https://fcm.googleapis.com/v1/projects/' .
            rawurlencode($projectId) .
            '/messages:send';

        $curl = curl_init($url);

        curl_setopt_array($curl, [

            CURLOPT_RETURNTRANSFER => true,

            CURLOPT_POST => true,

            CURLOPT_HTTPHEADER => [

                'Authorization: Bearer ' .
                    $tokenAcceso,

                'Content-Type: application/json'
            ],

            CURLOPT_POSTFIELDS => json_encode(
                $mensajeFirebase,
                JSON_UNESCAPED_UNICODE
            ),

            CURLOPT_TIMEOUT => 20
        ]);

        $respuesta = curl_exec($curl);

        if ($respuesta === false) {

            $error = curl_error($curl);

            curl_close($curl);

            return [
                'success' => false,
                'message' =>
                    'Error de conexión con Firebase.',
                'error' => $error
            ];
        }

        $codigoHttp =
            curl_getinfo(
                $curl,
                CURLINFO_HTTP_CODE
            );

        curl_close($curl);

        $respuestaFirebase =
            json_decode(
                $respuesta,
                true
            );

        if (
            $codigoHttp >= 200 &&
            $codigoHttp < 300
        ) {

            return [
                'success' => true,
                'message' =>
                    'Notificación enviada correctamente.',
                'firebase' =>
                    $respuestaFirebase
            ];
        }

        return [
            'success' => false,
            'message' =>
                'Firebase rechazó la notificación.',
            'http_code' =>
                $codigoHttp,
            'firebase' =>
                $respuestaFirebase
        ];

    } catch (Throwable $e) {

        return [
            'success' => false,
            'message' =>
                'No se pudo enviar la notificación.',
            'error' =>
                $e->getMessage()
        ];
    }
}
