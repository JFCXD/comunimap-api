<?php

/*
|--------------------------------------------------------------------------
| COMUNIMAP - AUTENTICACIÓN SEGURA DE LA API
|--------------------------------------------------------------------------
|
| Funciones:
| 1. Crear tokens firmados para administradores.
| 2. Leer Authorization en diferentes configuraciones Apache.
| 3. Comprobar firma y vencimiento del token.
| 4. Verificar Administrador y Activo en MySQL.
|
| No modifica tablas ni datos.
| No muestra tokens o claves en los registros.
|
*/

// =====================================================
// CODIFICACIÓN BASE64 URL
// =====================================================

function cmCodificarBase64Url(string $texto): string
{
    return rtrim(
        strtr(base64_encode($texto), '+/', '-_'),
        '='
    );
}

function cmDecodificarBase64Url(string $texto)
{
    if (
        $texto === '' ||
        !preg_match('/^[A-Za-z0-9_-]+$/', $texto)
    ) {
        return false;
    }

    $relleno = strlen($texto) % 4;

    if ($relleno !== 0) {
        $texto .= str_repeat('=', 4 - $relleno);
    }

    return base64_decode(
        strtr($texto, '-_', '+/'),
        true
    );
}

// =====================================================
// CLAVE PRIVADA DE RAILWAY
// =====================================================

function cmObtenerClaveApi(): string
{
    $clave = getenv('COMUNIMAP_API_SECRET');

    if (
        !is_string($clave) ||
        strlen($clave) < 32
    ) {
        throw new RuntimeException(
            'La clave privada de la API no está configurada'
        );
    }

    return $clave;
}

// =====================================================
// CREAR TOKEN DE ADMINISTRADOR
// =====================================================

function cmCrearToken(int $idUsuario): string
{
    if ($idUsuario <= 0) {
        throw new InvalidArgumentException(
            'Identificador de usuario inválido'
        );
    }

    $ahora = time();

    $datos = [
        'idUsuario' => $idUsuario,
        'emitido' => $ahora,
        'expira' => $ahora + (12 * 60 * 60)
    ];

    $contenido = cmCodificarBase64Url(
        json_encode(
            $datos,
            JSON_UNESCAPED_UNICODE |
            JSON_THROW_ON_ERROR
        )
    );

    $firma = hash_hmac(
        'sha256',
        $contenido,
        cmObtenerClaveApi(),
        true
    );

    return $contenido . '.' .
        cmCodificarBase64Url($firma);
}

// =====================================================
// OBTENER CABECERA AUTHORIZATION
// =====================================================

function cmObtenerCabeceraAutorizacion(): string
{
    // Método habitual en PHP.
    $posibles = [
        $_SERVER['HTTP_AUTHORIZATION'] ?? null,
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
    ];

    foreach ($posibles as $valor) {
        if (
            is_string($valor) &&
            trim($valor) !== ''
        ) {
            return trim($valor);
        }
    }

    // Algunos servidores entregan la cabecera aquí.
    if (function_exists('getallheaders')) {

        $cabeceras = getallheaders();

        if (is_array($cabeceras)) {

            foreach ($cabeceras as $nombre => $valor) {

                if (
                    strcasecmp(
                        (string) $nombre,
                        'Authorization'
                    ) === 0 &&
                    is_string($valor)
                ) {
                    return trim($valor);
                }
            }
        }
    }

    // Compatibilidad adicional con Apache.
    if (function_exists('apache_request_headers')) {

        $cabeceras = apache_request_headers();

        if (is_array($cabeceras)) {

            foreach ($cabeceras as $nombre => $valor) {

                if (
                    strcasecmp(
                        (string) $nombre,
                        'Authorization'
                    ) === 0 &&
                    is_string($valor)
                ) {
                    return trim($valor);
                }
            }
        }
    }

    return '';
}

// =====================================================
// LEER TOKEN EN FORMATO BEARER
// =====================================================

function cmLeerTokenPeticion(): ?string
{
    $cabecera = cmObtenerCabeceraAutorizacion();

    if ($cabecera === '') {
        error_log(
            'ComuniMap autenticación: ' .
            'No llegó la cabecera Authorization.'
        );

        return null;
    }

    if (
        !preg_match(
            '/^Bearer\s+([A-Za-z0-9._-]+)$/i',
            $cabecera,
            $coincidencias
        )
    ) {
        error_log(
            'ComuniMap autenticación: ' .
            'Formato de Authorization no válido.'
        );

        return null;
    }

    return $coincidencias[1];
}

// =====================================================
// VALIDAR FIRMA Y CADUCIDAD
// =====================================================

function cmValidarToken(?string $token): ?int
{
    if ($token === null) {
        return null;
    }

    if (
        $token === '' ||
        strlen($token) > 4096
    ) {
        error_log(
            'ComuniMap autenticación: ' .
            'Longitud del token no válida.'
        );

        return null;
    }

    $partes = explode('.', $token);

    if (count($partes) !== 2) {
        error_log(
            'ComuniMap autenticación: ' .
            'Estructura del token no válida.'
        );

        return null;
    }

    [$contenido, $firmaRecibida] = $partes;

    $firmaBinaria = cmDecodificarBase64Url(
        $firmaRecibida
    );

    if (
        $firmaBinaria === false ||
        strlen($firmaBinaria) !== 32
    ) {
        error_log(
            'ComuniMap autenticación: ' .
            'Firma del token mal formada.'
        );

        return null;
    }

    $firmaEsperada = hash_hmac(
        'sha256',
        $contenido,
        cmObtenerClaveApi(),
        true
    );

    if (
        !hash_equals(
            $firmaEsperada,
            $firmaBinaria
        )
    ) {
        error_log(
            'ComuniMap autenticación: ' .
            'La firma del token no coincide.'
        );

        return null;
    }

    $datosJson = cmDecodificarBase64Url(
        $contenido
    );

    if ($datosJson === false) {
        return null;
    }

    $datos = json_decode(
        $datosJson,
        true
    );

    if (!is_array($datos)) {
        return null;
    }

    $idUsuario = $datos['idUsuario'] ?? null;
    $emitido = $datos['emitido'] ?? null;
    $expira = $datos['expira'] ?? null;

    if (
        !is_int($idUsuario) ||
        !is_int($emitido) ||
        !is_int($expira)
    ) {
        return null;
    }

    $ahora = time();

    if (
        $idUsuario <= 0 ||
        $emitido > $ahora ||
        $expira <= $ahora ||
        $expira <= $emitido ||
        ($expira - $emitido) > 43200
    ) {
        error_log(
            'ComuniMap autenticación: ' .
            'Token caducado o fechas inválidas.'
        );

        return null;
    }

    return $idUsuario;
}

// =====================================================
// RESPUESTA DE ACCESO DENEGADO
// =====================================================

function cmRechazarAcceso(
    int $codigo,
    string $mensaje
): void
{
    http_response_code($codigo);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    header('Cache-Control: no-store');

    echo json_encode([
        'success' => false,
        'message' => $mensaje
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// =====================================================
// EXIGIR ADMINISTRADOR ACTIVO
// =====================================================

function cmExigirAdministrador(mysqli $conn): int
{
    $token = cmLeerTokenPeticion();

    $idUsuario = cmValidarToken($token);

    if ($idUsuario === null) {
        cmRechazarAcceso(
            401,
            'Sesión no válida o caducada. Inicia sesión nuevamente.'
        );
    }

    $consulta = $conn->prepare("
        SELECT
            rol,
            estado
        FROM usuarios
        WHERE idUsuario = ?
        LIMIT 1
    ");

    if (!$consulta) {
        throw new RuntimeException(
            'No se pudo comprobar la cuenta'
        );
    }

    $consulta->bind_param('i', $idUsuario);

    $consulta->execute();

    $resultado = $consulta->get_result();

    $usuario = $resultado->fetch_assoc();

    $consulta->close();

    if (!$usuario) {
        cmRechazarAcceso(
            401,
            'El usuario de la sesión ya no existe.'
        );
    }

    $rol = trim((string) $usuario['rol']);
    $estado = trim((string) $usuario['estado']);

    if (
        strcasecmp($rol, 'Administrador') !== 0 ||
        strcasecmp($estado, 'Activo') !== 0
    ) {
        error_log(
            'ComuniMap autenticación: ' .
            'Cuenta sin permisos administrativos.'
        );

        cmRechazarAcceso(
            403,
            'No tienes permisos para esta operación.'
        );
    }

    return $idUsuario;
}


// =====================================================
// EXIGIR CUENTA ACTIVA (ADMINISTRADOR O USUARIO)
// =====================================================
// Reutiliza exactamente los tokens firmados existentes.
// El servidor obtiene el idUsuario de la firma, nunca del cuerpo enviado.
function cmExigirUsuarioActivo(mysqli $conn): int
{
    $idUsuario = cmValidarToken(cmLeerTokenPeticion());
    if ($idUsuario === null) {
        cmRechazarAcceso(401, 'Sesión no válida o caducada. Inicia sesión nuevamente.');
    }

    $consulta = $conn->prepare(
        "SELECT rol, estado FROM usuarios WHERE idUsuario = ? LIMIT 1"
    );
    if (!$consulta) {
        throw new RuntimeException('No se pudo comprobar la cuenta');
    }
    $consulta->bind_param('i', $idUsuario);
    $consulta->execute();
    $usuario = $consulta->get_result()->fetch_assoc();
    $consulta->close();

    if (!$usuario || strcasecmp(trim((string)$usuario['estado']), 'Activo') !== 0) {
        cmRechazarAcceso(403, 'La cuenta no tiene acceso activo.');
    }
    if (!in_array(trim((string)$usuario['rol']), ['Usuario', 'Administrador'], true)) {
        cmRechazarAcceso(403, 'La cuenta no tiene permisos.');
    }
    return $idUsuario;
}
