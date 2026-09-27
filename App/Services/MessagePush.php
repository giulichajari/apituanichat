<?php
namespace App\Services;
use PDO;

/** Queue only persisted messages for their actual conversation participants. */
final class MessagePush
{
    public static function enqueue(PDO $db, int $messageId, int $senderId): int
    {
        $q=$db->prepare('SELECT id,chat_id,user_id,contenido,tipo FROM mensajes WHERE id=? AND user_id=?');
        $q->execute([$messageId,$senderId]);$message=$q->fetch(PDO::FETCH_ASSOC);
        if(!$message) return 0;
        $q=$db->prepare('SELECT 1 FROM chat_usuarios WHERE chat_id=? AND user_id=?');
        $q->execute([$message['chat_id'],$senderId]);
        if(!$q->fetchColumn()) return 0;
        $q=$db->prepare('SELECT DISTINCT d.user_id,d.fcm_token FROM chat_usuarios c JOIN device_tokens d ON d.user_id=c.user_id WHERE c.chat_id=? AND c.user_id<>? AND d.is_active=1');
        $q->execute([$message['chat_id'],$senderId]);
        $devices=$q->fetchAll(PDO::FETCH_ASSOC);
        $body=$message['tipo']==='texto'?mb_substr((string)$message['contenido'],0,160,'UTF-8'):'Te enviaron un archivo';
        $data=['type'=>'new_message','message_id'=>(string)$messageId,'chat_id'=>(string)$message['chat_id'],'body'=>$body?:'Tienes un mensaje nuevo'];
        $queue=new PushOutbox($db);$count=0;
        foreach($devices as$d){
            try {if($queue->enqueue((int)$d['user_id'],(string)$d['fcm_token'],$data,time()))++$count;}
            catch(\Throwable $e){error_log('MessagePush: no se pudo encolar un dispositivo');}
        }
        return $count;
    }
}
