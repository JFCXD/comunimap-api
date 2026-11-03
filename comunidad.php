<?php
// COMUNIMAP - Consultar muro de Comunidad desde Android.
// GET /comunidad.php?page=1 (Bearer tokenApi de usuario activo).
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['success'=>false,'message'=>'Método no permitido'],JSON_UNESCAPED_UNICODE);
    exit;
}
require_once __DIR__.'/conexion.php';
require_once __DIR__.'/autenticacion_api.php';

try {
    $idUsuario = cmExigirUsuarioActivo($conn);
    $pagina = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if ($pagina === false || $pagina === null || $pagina > 100000) {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'Página no válida'],JSON_UNESCAPED_UNICODE);
        exit;
    }

    $limite = 20;
    $offset = ($pagina-1)*$limite;
    $stmt = $conn->prepare("SELECT p.idPublicacion,p.tipo,p.titulo,p.descripcion,p.imagen,
                p.fechaActividad,p.horaActividad,p.lugarActividad,p.fechaCierre,
                p.fechaCreacion,p.idAutor,COALESCE(u.nombre,'OTB Universidad Alto') AS autor
            FROM publicaciones p LEFT JOIN usuarios u ON u.idUsuario=p.idAutor
            WHERE p.estado='Publicado'
            ORDER BY p.fechaCreacion DESC,p.idPublicacion DESC LIMIT ? OFFSET ?");
    if (!$stmt) throw new RuntimeException('Error al preparar publicaciones');
    $stmt->bind_param('ii',$limite,$offset);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $publicaciones = [];
    while ($fila = $resultado->fetch_assoc()) {
        $fila['idPublicacion'] = (int)$fila['idPublicacion'];
        $fila['idAutor'] = (int)$fila['idAutor'];
        $fila['opciones'] = [];
        $fila['totalVotos'] = 0;
        $fila['miVoto'] = null;
        $fila['encuestaAbierta'] = false;
        if ($fila['imagen']) {
            // El servidor Laravel sirve los archivos publicados, no la API PHP.
            $fila['imagenUrl'] = 'https://comunimap-production.up.railway.app/'.ltrim($fila['imagen'],'/');
        } else {
            $fila['imagenUrl'] = null;
        }
        $publicaciones[] = $fila;
    }
    $stmt->close();

    if ($publicaciones) {
        // Solo se consultan encuestas presentes en esta página y publicadas.
        $ids = [];
        foreach ($publicaciones as $pub) {
            if ($pub['tipo'] === 'Encuesta') $ids[] = $pub['idPublicacion'];
        }
        if ($ids) {
            $marcas = implode(',',array_fill(0,count($ids),'?'));
            $tipos = str_repeat('i',count($ids));
            $sql = "SELECT o.idPublicacion,o.idOpcion,o.texto,o.orden,
                    COUNT(v.idVoto) AS totalVotos
                    FROM opciones_encuesta o
                    LEFT JOIN votos_encuesta v ON v.idOpcion=o.idOpcion AND v.idPublicacion=o.idPublicacion
                    WHERE o.idPublicacion IN ($marcas)
                    GROUP BY o.idPublicacion,o.idOpcion,o.texto,o.orden
                    ORDER BY o.idPublicacion,o.orden,o.idOpcion";
            $q = $conn->prepare($sql);
            if (!$q) throw new RuntimeException('Error al consultar opciones');
            $q->bind_param($tipos,...$ids);
            $q->execute();
            $opciones = [];
            foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $o) {
                $id = (int)$o['idPublicacion'];
                $opciones[$id][] = [
                    'idOpcion'=>(int)$o['idOpcion'],
                    'texto'=>$o['texto'],
                    'orden'=>(int)$o['orden'],
                    'totalVotos'=>(int)$o['totalVotos']
                ];
            }
            $q->close();

            $sqlVotos = "SELECT idPublicacion,idOpcion FROM votos_encuesta
                WHERE idUsuario=? AND idPublicacion IN ($marcas)";
            $v = $conn->prepare($sqlVotos);
            if (!$v) throw new RuntimeException('Error al consultar votos propios');
            $v->bind_param('i'.$tipos,$idUsuario,...$ids);
            $v->execute();
            $votados = [];
            foreach ($v->get_result()->fetch_all(MYSQLI_ASSOC) as $fila) {
                $votados[(int)$fila['idPublicacion']] = (int)$fila['idOpcion'];
            }
            $v->close();

            foreach ($publicaciones as &$pub) {
                if ($pub['tipo'] !== 'Encuesta') continue;
                $id = $pub['idPublicacion'];
                $pub['opciones'] = $opciones[$id] ?? [];
                foreach ($pub['opciones'] as $op) $pub['totalVotos'] += $op['totalVotos'];
                $pub['miVoto'] = $votados[$id] ?? null;
                $pub['encuestaAbierta'] = $pub['fechaCierre'] !== null
                    && strtotime($pub['fechaCierre']) > time();
            }
            unset($pub);
        }
    }

    echo json_encode([
        'success'=>true,
        'page'=>$pagina,
        'hasMore'=>count($publicaciones)===$limite,
        'totalPagina'=>count($publicaciones),
        'publicaciones'=>$publicaciones
    ],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('ComuniMap Comunidad GET: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'No se pudo consultar Comunidad.'],JSON_UNESCAPED_UNICODE);
} finally {
    $conn->close();
}
