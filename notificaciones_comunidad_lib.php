<?php
/**
 * Envío de avisos comunitarios reutilizando dispositivos_fcm y notificaciones.
 * Requiere migración: notificaciones.idPublicacion y unique(idUsuario,idPublicacion).
 * Se llama DESPUÉS de guardar la publicación. Los INSERT únicos garantizan
 * un solo aviso interno y un único intento push por dispositivo y publicación.
 */
require_once __DIR__ . '/enviar_notificacion_push.php';

function cmNotificarPublicacionComunidad(mysqli $conn, int $idPublicacion): array
{
    if ($idPublicacion <= 0) {
        throw new InvalidArgumentException('Publicación no válida');
    }
    $pub = $conn->prepare("SELECT tipo,titulo,estado FROM publicaciones WHERE idPublicacion=? LIMIT 1");
    if (!$pub) throw new RuntimeException('No se pudo consultar publicación');
    $pub->bind_param('i', $idPublicacion);
    $pub->execute();
    $publicacion = $pub->get_result()->fetch_assoc();
    $pub->close();

    if (!$publicacion || $publicacion['estado'] !== 'Publicado') {
        return ['success'=>true,'resultado'=>'No publicado; no se notificó','destinatarios'=>0,'pushEnviados'=>0];
    }

    $titulos = [
        'Comunicado' => 'Nuevo comunicado de la OTB',
        'Actividad' => 'Nueva actividad comunitaria',
        'Encuesta' => 'Nueva encuesta disponible'
    ];
    if (!isset($titulos[$publicacion['tipo']])) {
        throw new RuntimeException('Tipo de publicación no válido');
    }
    $titulo = $titulos[$publicacion['tipo']];
    $mensaje = trim((string)$publicacion['titulo']);
    // Las notificaciones actuales admiten mensajes de 255 caracteres.
    // Cortar en caracteres completos para preservar UTF-8 en títulos acentuados.
    if (preg_match('/^.{0,200}/us', $mensaje, $trozo)) {
        $mensaje = $trozo[0];
    }

    $personas = $conn->query("SELECT idUsuario FROM usuarios WHERE rol='Usuario' AND estado='Activo'");
    if (!$personas) throw new RuntimeException('No se pudieron consultar vecinos');

    $insertar = $conn->prepare("INSERT INTO notificaciones
        (idUsuario,idReporte,idPublicacion,titulo,mensaje,tipo,leida)
        VALUES (?,NULL,?,?,?,'COMUNIDAD',0)
        ON DUPLICATE KEY UPDATE idNotificacion=idNotificacion");
    $dispositivos = $conn->prepare("SELECT DISTINCT token FROM dispositivos_fcm WHERE idUsuario=?");
    if (!$insertar || !$dispositivos) throw new RuntimeException('No se pudieron preparar avisos');

    $avisosNuevos = [];
    $conn->begin_transaction();
    try {
        while ($persona = $personas->fetch_assoc()) {
            $uid=(int)$persona['idUsuario'];
            $insertar->bind_param('iiss',$uid,$idPublicacion,$titulo,$mensaje);
            $insertar->execute();
            if ($insertar->affected_rows === 1) {
                $avisosNuevos[] = ['usuario'=>$uid,'idNotificacion'=>(int)$conn->insert_id];
            }
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    } finally {
        $insertar->close();
    }

    $enviados=0; $fallidos=0;
    foreach ($avisosNuevos as $aviso) {
        $uid=$aviso['usuario'];
        $dispositivos->bind_param('i',$uid);
        $dispositivos->execute();
        $rs=$dispositivos->get_result();
        while ($fila=$rs->fetch_assoc()) {
            $token=trim((string)$fila['token']);
            if ($token==='') continue;
            $resultado=enviarNotificacionPush($token,$titulo,$mensaje,[
                'tipo'=>'COMUNIDAD',
                'idPublicacion'=>(string)$idPublicacion,
                'idNotificacion'=>(string)$aviso['idNotificacion']
            ]);
            if (!empty($resultado['success'])) {
                $enviados++;
            } else {
                $fallidos++;
                error_log('ComuniMap Comunidad FCM: no se pudo enviar aviso '.$aviso['idNotificacion']);
            }
        }
        $rs->free();
    }
    $dispositivos->close();
    $personas->free();
    return [
        'success'=>true,
        'destinatarios'=>count($avisosNuevos),
        'pushEnviados'=>$enviados,
        'pushFallidos'=>$fallidos
    ];
}
