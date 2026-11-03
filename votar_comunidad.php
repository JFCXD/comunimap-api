<?php
// COMUNIMAP - Registrar un voto desde Android.
// POST /votar_comunidad.php | Authorization: Bearer tokenApi
// JSON: {"idPublicacion": 1, "idOpcion": 2}
// El idUsuario siempre se obtiene del token firmado, nunca del JSON.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['success'=>false,'message'=>'Método no permitido'],JSON_UNESCAPED_UNICODE);
    exit;
}
require_once __DIR__.'/conexion.php';
require_once __DIR__.'/autenticacion_api.php';

$enTransaccion = false;
try {
    $idUsuario = cmExigirUsuarioActivo($conn);
    $datos = json_decode(file_get_contents('php://input'),true);
    if (!is_array($datos)) {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'Datos de votación inválidos'],JSON_UNESCAPED_UNICODE);
        exit;
    }
    $idPublicacion = filter_var($datos['idPublicacion'] ?? null,FILTER_VALIDATE_INT,
        ['options'=>['min_range'=>1]]);
    $idOpcion = filter_var($datos['idOpcion'] ?? null,FILTER_VALIDATE_INT,
        ['options'=>['min_range'=>1]]);
    if (!$idPublicacion || !$idOpcion) {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'Encuesta u opción inválida'],JSON_UNESCAPED_UNICODE);
        exit;
    }

    $conn->begin_transaction();
    $enTransaccion = true;
    // Serializa la votación por encuesta para evitar carreras con cambios de estado.
    $p = $conn->prepare("SELECT idPublicacion,tipo,estado,
            (fechaCierre IS NOT NULL AND fechaCierre > NOW()) AS abierta
            FROM publicaciones WHERE idPublicacion=? FOR UPDATE");
    if (!$p) throw new RuntimeException('No se pudo consultar la encuesta');
    $p->bind_param('i',$idPublicacion);
    $p->execute();
    $publicacion = $p->get_result()->fetch_assoc();
    $p->close();
    if (!$publicacion || $publicacion['tipo'] !== 'Encuesta'
        || $publicacion['estado'] !== 'Publicado' || !(bool)$publicacion['abierta']) {
        $conn->rollback(); $enTransaccion=false;
        http_response_code(409);
        echo json_encode(['success'=>false,'message'=>'Esta encuesta no está disponible o ya finalizó.'],JSON_UNESCAPED_UNICODE);
        exit;
    }

    $o = $conn->prepare("SELECT idOpcion FROM opciones_encuesta
        WHERE idOpcion=? AND idPublicacion=? LIMIT 1");
    if (!$o) throw new RuntimeException('No se pudo comprobar la opción');
    $o->bind_param('ii',$idOpcion,$idPublicacion);
    $o->execute();
    $opcionValida = (bool)$o->get_result()->fetch_assoc();
    $o->close();
    if (!$opcionValida) {
        $conn->rollback(); $enTransaccion=false;
        http_response_code(422);
        echo json_encode(['success'=>false,'message'=>'La opción no pertenece a esta encuesta.'],JSON_UNESCAPED_UNICODE);
        exit;
    }

    $v = $conn->prepare("INSERT INTO votos_encuesta (idPublicacion,idOpcion,idUsuario)
        VALUES (?,?,?)");
    if (!$v) throw new RuntimeException('No se pudo preparar el voto');
    $v->bind_param('iii',$idPublicacion,$idOpcion,$idUsuario);
    $v->execute();
    $v->close();
    $conn->commit(); $enTransaccion=false;

    echo json_encode([
        'success'=>true,
        'message'=>'Tu voto quedó registrado.',
        'idPublicacion'=>$idPublicacion,
        'idOpcion'=>$idOpcion
    ],JSON_UNESCAPED_UNICODE);
} catch (mysqli_sql_exception $e) {
    if ($enTransaccion) $conn->rollback();
    if ((int)$e->getCode() === 1062) {
        http_response_code(409);
        echo json_encode(['success'=>false,'message'=>'Ya registraste tu voto en esta encuesta.'],JSON_UNESCAPED_UNICODE);
    } else {
        error_log('ComuniMap votar: '.$e->getCode());
        http_response_code(500);
        echo json_encode(['success'=>false,'message'=>'No se pudo registrar el voto.'],JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    if ($enTransaccion) $conn->rollback();
    error_log('ComuniMap votar: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'No se pudo registrar el voto.'],JSON_UNESCAPED_UNICODE);
} finally {
    $conn->close();
}
