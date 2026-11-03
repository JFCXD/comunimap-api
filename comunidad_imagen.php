<?php
/**
 * Subida de imagen Comunidad desde administrador Android.
 * POST multipart/form-data: idPublicacion, imagen
 * Authorization: Bearer tokenApi
 *
 * La API valida al Administrador y transfiere la foto al servicio Laravel,
 * que es el único que puede escribir en comunimap-volume.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success'=>false,'message'=>'Método no permitido'],JSON_UNESCAPED_UNICODE);
    exit;
}
require_once __DIR__.'/conexion.php';
require_once __DIR__.'/autenticacion_api.php';

function cmErrorImagen(int $estado, string $mensaje): void {
    http_response_code($estado);
    echo json_encode(['success'=>false,'message'=>$mensaje],JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    cmExigirAdministrador($conn);
    $id = filter_var($_POST['idPublicacion'] ?? null, FILTER_VALIDATE_INT,
        ['options'=>['min_range'=>1]]);
    if ($id === false || $id === null) cmErrorImagen(422,'Selecciona una publicación válida.');
    if (!isset($_FILES['imagen']) || !is_array($_FILES['imagen'])) {
        cmErrorImagen(422,'Selecciona una fotografía.');
    }
    $archivo = $_FILES['imagen'];
    if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        cmErrorImagen(422,'No se pudo recibir la fotografía.');
    }
    $temporal = $archivo['tmp_name'] ?? '';
    $tamano = (int)($archivo['size'] ?? 0);
    if ($tamano < 1 || $tamano > 4*1024*1024 || !is_uploaded_file($temporal)) {
        cmErrorImagen(422,'La fotografía debe tener un máximo de 4 MB.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($temporal);
    $permitidos=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if (!isset($permitidos[$mime]) || @getimagesize($temporal) === false) {
        cmErrorImagen(422,'La fotografía debe ser JPG, PNG o WEBP válida.');
    }

    // Solo publicaciones reales, el endpoint Laravel vuelve a validar la existencia.
    $stmt = $conn->prepare("SELECT idPublicacion,imagen,estado FROM publicaciones WHERE idPublicacion=? LIMIT 1");
    if (!$stmt) throw new RuntimeException('No se pudo consultar la publicación');
    $stmt->bind_param('i',$id);
    $stmt->execute();
    $publicacion=$stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$publicacion) cmErrorImagen(404,'Publicación no encontrada.');
    if ($publicacion['imagen'] !== null || $publicacion['estado']==='Archivado') {
        cmErrorImagen(409,'Esta publicación ya tiene fotografía o está archivada.');
    }

    $secreto=getenv('COMUNIMAP_IMAGE_UPLOAD_SECRET');
    if (!is_string($secreto) || strlen($secreto)<32) {
        throw new RuntimeException('Falta configurar COMUNIMAP_IMAGE_UPLOAD_SECRET en API');
    }
    $curl = curl_init('https://comunimap-production.up.railway.app/comunidad/imagen/subir');
    if ($curl === false) throw new RuntimeException('No se pudo abrir la conexión con Laravel');
    curl_setopt_array($curl,[
        CURLOPT_POST=>true,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_TIMEOUT=>35,
        CURLOPT_HTTPHEADER=>['X-Comunimap-Image-Secret: '.$secreto,'Accept: application/json'],
        CURLOPT_POSTFIELDS=>[
            'idPublicacion'=>(string)$id,
            'imagen'=>new CURLFile($temporal,$mime,'comunidad.'.$permitidos[$mime]),
        ],
    ]);
    $respuesta=curl_exec($curl);
    $codigo=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
    $error=$respuesta === false ? curl_error($curl) : '';
    curl_close($curl);
    if ($respuesta === false) {
        error_log('ComuniMap imagen: fallo red con Laravel: '.$error);
        cmErrorImagen(502,'No se pudo conectar con el almacenamiento de imágenes.');
    }
    $datos=json_decode($respuesta,true);
    if ($codigo < 200 || $codigo >= 300 || !is_array($datos) || empty($datos['success'])) {
        error_log('ComuniMap imagen: Laravel rechazó subida, HTTP '.$codigo);
        $mensaje=is_array($datos) && isset($datos['message']) && is_string($datos['message'])
            ? $datos['message'] : 'No se pudo guardar la fotografía.';
        cmErrorImagen(($codigo>=400 && $codigo<=499)?$codigo:502,$mensaje);
    }
    echo json_encode([
        'success'=>true,
        'message'=>'Fotografía guardada en el volumen permanente.',
        'imagen'=>$datos['imagen'],
        'imagenUrl'=>$datos['imagenUrl'],
        'idPublicacion'=>(int)$id,
    ],JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('ComuniMap imagen: '.$e->getMessage());
    cmErrorImagen(500,'No se pudo guardar la fotografía.');
} finally {
    $conn->close();
}
