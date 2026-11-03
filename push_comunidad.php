<?php
// Servicio interno Laravel -> API para notificar publicaciones de Comunidad.
// Usa el mismo secreto privado que push_notificaciones_web.php.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') {
    header('Allow: POST'); http_response_code(405);
    echo json_encode(['success'=>false,'message'=>'Método no permitido']); exit;
}
$clave=getenv('COMUNIMAP_WEB_PUSH_SECRET');
$recibida=$_SERVER['HTTP_X_COMUNIMAP_WEB_PUSH_SECRET'] ?? '';
if (!is_string($clave) || strlen($clave)<32 || !is_string($recibida) || !hash_equals($clave,$recibida)) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Acceso denegado']); exit;
}
$datos=json_decode(file_get_contents('php://input'),true);
$id=filter_var($datos['idPublicacion'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if ($id===false || $id===null) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Identificador inválido']); exit;
}
require_once __DIR__.'/conexion.php';
require_once __DIR__.'/notificaciones_comunidad_lib.php';
try {
    echo json_encode(cmNotificarPublicacionComunidad($conn,(int)$id),JSON_UNESCAPED_UNICODE);
} catch(Throwable $e) {
    error_log('ComuniMap push Comunidad: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'No se pudieron procesar los avisos comunitarios'],JSON_UNESCAPED_UNICODE);
} finally {
    $conn->close();
}
