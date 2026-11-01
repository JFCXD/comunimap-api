<?php

/*
|--------------------------------------------------------------------------
| COMUNIMAP - AUTENTICACIÓN DE LA API
|--------------------------------------------------------------------------
|
| Este archivo permite:
|
| 1. Crear un token firmado para un usuario autenticado.
| 2. Verificar que el token sea auténtico.
| 3. Comprobar que el usuario sea Administrador y esté Activo.
|
| No modifica la base de datos.
| No crea sesiones en MySQL.
| No cambia el inicio de sesión actual.
|
*/

// =====================================================
// CODIFICACIÓN SEGURA PARA EL TOKEN
// =====================================================

function cmCodificarBase64Url(string $texto): string
{
    return rtrim(
        strtr(
            base64_encode($texto),
            '+/',
            '-_'
        ),
        '='
    );
}

function cmDecodificarBase64Url(string $texto)
{
    $texto = strtr($texto, '-_', '+/');

    $relleno = strlen($texto) % 4;

    if ($relleno !== 0) {
        $texto .= str_repeat(
            '=',
            4 - $relleno
        );
    }

    return base64_decode($texto, true);
}

// =====================================================
// OBTENER CLAVE PRIVADA DESDE RAILWAY
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
// CREAR TOKEN DESPUÉS DE UN LOGIN CORRECTO
// =====================================================

function cmCrearToken(int $idUsuario): string
{
    if ($idUsuario <= 0) {
        throw new InvalidArgumentException(
            'Identificador de usuario no válido'
        );
    }

    $ahora = time();

    // Duración del token: 12 horas.
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

    return $contenido
        . '.'
        . cmCodificarBase64Url($firma);
}

// =====================================================
// OBTENER TOKEN ENVIADO POR ANDROID
// =====================================================

function cmLeerTokenPeticion(): ?string
{
    $cabecera =
        $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';

    if (!is_string($cabecera)) {
        return null;
    }

    // Formato esperado:
    // Authorization: Bearer TOKEN

    if (
        !preg_match(
            '/^Bearer\s+([A-Za-z0-9._-]+)$/i',
            trim($cabecera),
            $coincidencias
        )
    ) {
        return null;
    }

    return $coincidencias[1];
}

// =====================================================
// VALIDAR FIRMA Y FECHA DEL TOKEN
// =====================================================

function cmValidarToken(?string $token): ?int
{
    if (
        $token === null ||
        strlen($token) > 4096
    ) {
        return null;
    }

    $partes = explode('.', $token);

    if (count($partes) !== 2) {
        return null;
    }

    [$contenido, $firmaRecibida] = $partes;

    if (
        $contenido === '' ||
        $firmaRecibida === ''
    ) {
        return null;
    }

    $firmaBinaria = cmDecodificarBase64Url(
        $firmaRecibida
    );

    if ($firmaBinaria === false) {
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
        ($expira - $emitido) > (12 * 60 * 60)
    ) {
        return null;
    }

    return $idUsuario;
}

// =====================================================
// RESPONDER CUANDO NO EXISTEN PERMISOS
// =====================================================

function cmRechazarAcceso(
    int $codigo,
    string $mensaje
): void {
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

    // Comprobar los permisos actuales en MySQL.
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
            'No se pudo comprobar el usuario'
        );
    }

    $consulta->bind_param(
        'i',
        $idUsuario
    );

    $consulta->execute();

    $resultado = $consulta->get_result();

    $usuario = $resultado->fetch_assoc();

    $consulta->close();

    if (!$usuario) {
        cmRechazarAcceso(
            401,
            'La sesión ya no corresponde a un usuario válido.'
        );
    }

    $rol = trim(
        (string) $usuario['rol']
    );

    $estado = trim(
        (string) $usuario['estado']
    );

    if (
        strcasecmp(
            $rol,
            'Administrador'
        ) !== 0
        ||
        strcasecmp(
            $estado,
            'Activo'
        ) !== 0
    ) {
        cmRechazarAcceso(
            403,
            'No tienes permisos para realizar esta operación.'
        );
    }

    // La petición pertenece a un administrador activo.
    return $idUsuario;
}
