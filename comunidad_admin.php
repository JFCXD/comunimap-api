<?php
// ComuniMap - administración de Comunidad exclusivamente para Administrador.
// POST JSON: accion=crear | editar | estado
// Siempre usa el tokenApi firmado; jamás confía en un idAutor enviado por Android.
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

function cmComunidadResponder(int $codigo,string $mensaje): void {
    http_response_code($codigo);
    echo json_encode(['success'=>false,'message'=>$mensaje],JSON_UNESCAPED_UNICODE);
    exit;
}
function cmComunidadTexto($valor,int $max): ?string {
    if (!is_string($valor)) return null;
    $texto=trim($valor);
    if ($texto==='' || preg_match_all('/./us',$texto)>$max) return null;
    return $texto;
}
function cmComunidadFecha($valor,string $formato): ?string {
    if (!is_string($valor)) return null;
    $valor=trim($valor);
    $fecha=DateTimeImmutable::createFromFormat('!'.$formato,$valor,new DateTimeZone('America/La_Paz'));
    return $fecha && $fecha->format($formato)===$valor ? $valor : null;
}
function cmComunidadId($valor): ?int {
    $id=filter_var($valor,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    return $id === false || $id === null ? null : (int)$id;
}

$transaccion=false;
try {
    $idAutor=cmExigirAdministrador($conn);
    $datos=json_decode(file_get_contents('php://input'),true);
    if (!is_array($datos)) cmComunidadResponder(400,'Datos JSON inválidos.');
    $accion=$datos['accion'] ?? null;
    if (!in_array($accion,['crear','editar','estado'],true)) {
        cmComunidadResponder(400,'Operación no válida.');
    }

    $conn->begin_transaction();
    $transaccion=true;
    $idPublicacion=null;

    if ($accion==='crear') {
        $tipo=$datos['tipo'] ?? null;
        if (!in_array($tipo,['Comunicado','Actividad','Encuesta'],true)) {
            cmComunidadResponder(422,'Selecciona un tipo de publicación válido.');
        }
        $titulo=cmComunidadTexto($datos['titulo'] ?? null,180);
        $descripcion=cmComunidadTexto($datos['descripcion'] ?? null,10000);
        $estado=$datos['estado'] ?? 'Borrador';
        if (!$titulo || !$descripcion || !in_array($estado,['Borrador','Publicado'],true)) {
            cmComunidadResponder(422,'Revisa el título, la descripción y el estado.');
        }
        // Imágenes desde Android quedan fuera de esta primera integración.
        if (!empty($datos['imagen']) || !empty($datos['imagenUrl'])) {
            cmComunidadResponder(422,'Por ahora las imágenes se adjuntan desde Laravel.');
        }

        $fechaActividad=null; $horaActividad=null; $lugarActividad=null;
        $fechaCierre=null; $opciones=[];
        if ($tipo==='Actividad') {
            $fechaActividad=cmComunidadFecha($datos['fechaActividad'] ?? null,'Y-m-d');
            $horaActividad=cmComunidadFecha($datos['horaActividad'] ?? null,'H:i');
            $lugarActividad=cmComunidadTexto($datos['lugarActividad'] ?? null,255);
            if (!$fechaActividad || !$horaActividad || !$lugarActividad) {
                cmComunidadResponder(422,'Completa la fecha, hora y lugar de la actividad.');
            }
        }
        if ($tipo==='Encuesta') {
            $fechaCierre=cmComunidadFecha($datos['fechaCierre'] ?? null,'Y-m-d H:i:s');
            $ahora=new DateTimeImmutable('now',new DateTimeZone('America/La_Paz'));
            if (!$fechaCierre || new DateTimeImmutable($fechaCierre,new DateTimeZone('America/La_Paz')) <= $ahora) {
                cmComunidadResponder(422,'La encuesta necesita una fecha de cierre futura (AAAA-MM-DD HH:MM:SS).');
            }
            $opciones=$datos['opciones'] ?? null;
            if (!is_array($opciones) || count($opciones)<2 || count($opciones)>10 ||
                array_keys($opciones)!==range(0,count($opciones)-1)) {
                cmComunidadResponder(422,'La encuesta necesita de 2 a 10 opciones.');
            }
            $unicas=[];
            foreach ($opciones as &$opcion) {
                $opcion=cmComunidadTexto($opcion,255);
                if (!$opcion) cmComunidadResponder(422,'Completa todas las opciones.');
                $normalizada=strtolower(strtr($opcion,['Á'=>'á','É'=>'é','Í'=>'í','Ó'=>'ó','Ú'=>'ú','Ü'=>'ü','Ñ'=>'ñ']));
                if (in_array($normalizada,$unicas,true)) {
                    cmComunidadResponder(422,'Las opciones de la encuesta deben ser diferentes.');
                }
                $unicas[]=$normalizada;
            }
            unset($opcion);
        }
        $stmt=$conn->prepare("INSERT INTO publicaciones
            (idAutor,tipo,titulo,descripcion,imagen,fechaActividad,horaActividad,lugarActividad,fechaCierre,estado)
            VALUES (?,?,?,?,NULL,?,?,?,?,?)");
        if (!$stmt) throw new RuntimeException('Error al preparar publicación');
        $stmt->bind_param('issssssss',$idAutor,$tipo,$titulo,$descripcion,
            $fechaActividad,$horaActividad,$lugarActividad,$fechaCierre,$estado);
        $stmt->execute();
        $idPublicacion=(int)$conn->insert_id;
        $stmt->close();

        if ($tipo==='Encuesta') {
            $o=$conn->prepare("INSERT INTO opciones_encuesta (idPublicacion,texto,orden) VALUES (?,?,?)");
            if (!$o) throw new RuntimeException('Error al preparar opciones');
            foreach ($opciones as $indice=>$textoOpcion) {
                $orden=$indice+1;
                $o->bind_param('isi',$idPublicacion,$textoOpcion,$orden);
                $o->execute();
            }
            $o->close();
        }

    } else {
        $idPublicacion=cmComunidadId($datos['idPublicacion'] ?? null);
        if (!$idPublicacion) cmComunidadResponder(422,'Identificador de publicación inválido.');
        $p=$conn->prepare("SELECT tipo,estado FROM publicaciones WHERE idPublicacion=? FOR UPDATE");
        if (!$p) throw new RuntimeException('Error al buscar publicación');
        $p->bind_param('i',$idPublicacion);
        $p->execute();
        $pub=$p->get_result()->fetch_assoc();
        $p->close();
        if (!$pub) cmComunidadResponder(404,'Publicación no encontrada.');

        if ($accion==='estado') {
            $estado=$datos['estado'] ?? null;
            if (!in_array($estado,['Borrador','Publicado','Archivado'],true)) {
                cmComunidadResponder(422,'Estado de publicación no válido.');
            }
            if ($pub['tipo']==='Encuesta' && $estado==='Publicado') {
                $q=$conn->prepare("SELECT p.fechaCierre,COUNT(o.idOpcion) AS cantidad
                    FROM publicaciones p LEFT JOIN opciones_encuesta o ON o.idPublicacion=p.idPublicacion
                    WHERE p.idPublicacion=? GROUP BY p.fechaCierre");
                if (!$q) throw new RuntimeException('Error al validar encuesta');
                $q->bind_param('i',$idPublicacion);
                $q->execute();
                $validacion=$q->get_result()->fetch_assoc();
                $q->close();
                if (!$validacion || !$validacion['fechaCierre'] || (int)$validacion['cantidad']<2) {
                    cmComunidadResponder(422,'La encuesta necesita fecha de cierre y dos opciones.');
                }
            }
            $u=$conn->prepare("UPDATE publicaciones SET estado=? WHERE idPublicacion=?");
            if (!$u) throw new RuntimeException('Error al preparar estado');
            $u->bind_param('si',$estado,$idPublicacion);
            $u->execute(); $u->close();
        } else {
            // Igual que Laravel: ninguna encuesta con votos se puede editar.
            if ($pub['tipo']==='Encuesta') {
                $v=$conn->prepare("SELECT idVoto FROM votos_encuesta WHERE idPublicacion=? LIMIT 1");
                if (!$v) throw new RuntimeException('Error al verificar votos');
                $v->bind_param('i',$idPublicacion); $v->execute();
                $tieneVotos=(bool)$v->get_result()->fetch_assoc(); $v->close();
                if ($tieneVotos) cmComunidadResponder(409,'Esta encuesta ya recibió votos y no se puede editar.');
            }
            $titulo=cmComunidadTexto($datos['titulo'] ?? null,180);
            $descripcion=cmComunidadTexto($datos['descripcion'] ?? null,10000);
            if (!$titulo || !$descripcion) cmComunidadResponder(422,'Completa título y descripción.');
            $fechaActividad=null; $horaActividad=null; $lugarActividad=null; $fechaCierre=null;
            if ($pub['tipo']==='Actividad') {
                $fechaActividad=cmComunidadFecha($datos['fechaActividad'] ?? null,'Y-m-d');
                $horaActividad=cmComunidadFecha($datos['horaActividad'] ?? null,'H:i');
                $lugarActividad=cmComunidadTexto($datos['lugarActividad'] ?? null,255);
                if (!$fechaActividad || !$horaActividad || !$lugarActividad) {
                    cmComunidadResponder(422,'Completa la fecha, hora y lugar.');
                }
            }
            if ($pub['tipo']==='Encuesta' && array_key_exists('fechaCierre',$datos)) {
                $fechaCierre=cmComunidadFecha($datos['fechaCierre'],'Y-m-d H:i:s');
                if (!$fechaCierre || new DateTimeImmutable($fechaCierre,new DateTimeZone('America/La_Paz'))<=
                    new DateTimeImmutable('now',new DateTimeZone('America/La_Paz'))) {
                    cmComunidadResponder(422,'La fecha de cierre debe ser futura.');
                }
            }
            // No modificar opciones ni tipo después de crear.
            if ($pub['tipo']==='Actividad') {
                $u=$conn->prepare("UPDATE publicaciones SET titulo=?,descripcion=?,fechaActividad=?,horaActividad=?,lugarActividad=? WHERE idPublicacion=?");
                $u->bind_param('sssssi',$titulo,$descripcion,$fechaActividad,$horaActividad,$lugarActividad,$idPublicacion);
            } elseif ($pub['tipo']==='Encuesta' && $fechaCierre!==null) {
                $u=$conn->prepare("UPDATE publicaciones SET titulo=?,descripcion=?,fechaCierre=? WHERE idPublicacion=?");
                $u->bind_param('sssi',$titulo,$descripcion,$fechaCierre,$idPublicacion);
            } else {
                $u=$conn->prepare("UPDATE publicaciones SET titulo=?,descripcion=? WHERE idPublicacion=?");
                $u->bind_param('ssi',$titulo,$descripcion,$idPublicacion);
            }
            if (!$u) throw new RuntimeException('Error al preparar edición');
            $u->execute(); $u->close();
        }
    }
    $conn->commit(); $transaccion=false;
    echo json_encode([
        'success'=>true,
        'message'=>$accion==='crear'?'Publicación creada correctamente.':
            ($accion==='editar'?'Publicación actualizada.':'Estado actualizado.'),
        'idPublicacion'=>$idPublicacion
    ],JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($transaccion) $conn->rollback();
    error_log('ComuniMap Comunidad admin: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'No se pudo realizar la operación.'],JSON_UNESCAPED_UNICODE);
} finally {
    $conn->close();
}
