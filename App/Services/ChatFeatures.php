<?php
declare(strict_types=1);
namespace App\Services;
use PDO;
use RuntimeException;
use InvalidArgumentException;

final class ChatFeatures
{
    public function __construct(private PDO $db) {}
    private function q(string $sql,array $args=[]): \PDOStatement { $s=$this->db->prepare($sql);$s->execute($args);return $s; }
    private function atomic(callable $work): mixed {
        $owns=!$this->db->inTransaction();if($owns)$this->db->beginTransaction();
        try{$value=$work();if($owns)$this->db->commit();return $value;}
        catch(\Throwable $e){if($owns&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function access(int $user,int $chat): void {
        if($user<1||$chat<1||!$this->q('SELECT 1 FROM chat_usuarios WHERE chat_id=? AND user_id=?',[$chat,$user])->fetchColumn())throw new RuntimeException('Conversación no disponible',403);
    }
    private function lockChat(int $user,int $chat): void {
        $lock=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $this->q('SELECT id FROM chats WHERE id=?'.$lock,[$chat]);$this->access($user,$chat);
    }
    public function message(int $user,int $id): array {
        $row=$this->q('SELECT m.*,x.reply_to,x.forwarded_from,x.edited_at,x.deleted_at,x.view_once,x.attachment_id,p.hidden_at,p.favorite,p.reaction FROM mensajes m LEFT JOIN chat_message_features x ON x.message_id=m.id LEFT JOIN chat_message_users p ON p.message_id=m.id AND p.user_id=? WHERE m.id=?',[$user,$id])->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new RuntimeException('Mensaje no disponible',404);
        $this->access($user,(int)$row['chat_id']);if($row['hidden_at'])throw new RuntimeException('Mensaje no disponible',404);return $row;
    }
    public function ensure(int $user,int $peer): int {
        if($peer<1||$peer===$user||!$this->q('SELECT id FROM users WHERE id=?',[$peer])->fetchColumn())throw new InvalidArgumentException('Contacto inválido');
        $model=new \App\Models\ChatModel();return (int)$model->findOrCreateChat([$user,$peer]);
    }
    private function putUser(int $id,int $user,string $field,mixed $value): void {
        if(!in_array($field,['delivered_at','read_at','hidden_at','favorite','reaction'],true))throw new InvalidArgumentException('Campo inválido');
        try{$this->q('INSERT INTO chat_message_users(message_id,user_id) VALUES(?,?)',[$id,$user]);}catch(\PDOException $e){if((string)$e->getCode()!=='23000')throw $e;}
        $this->q("UPDATE chat_message_users SET $field=? WHERE message_id=? AND user_id=?",[$value,$id,$user]);
    }
    private function ensureMeta(int $id): void {
        try{$this->q('INSERT INTO chat_message_features(message_id) VALUES(?)',[$id]);}catch(\PDOException $e){if((string)$e->getCode()!=='23000')throw $e;}
    }
    public function list(int $user,int $chat,int $before=PHP_INT_MAX,array $ids=[]): array {
        $this->access($user,$chat);
        if(count($ids)>100)throw new InvalidArgumentException('Demasiados mensajes');
        $ids=array_values(array_filter(array_map('intval',$ids),fn($id)=>$id>0));
        $filter=$ids?' AND m.id IN ('.implode(',',array_fill(0,count($ids),'?')).')':'';
        $rows=$this->q('SELECT m.*,u.name AS sender_name,x.reply_to,x.forwarded_from,x.edited_at,x.deleted_at,x.view_once,x.attachment_id,p.favorite,p.reaction,v.consumed_at,f.url AS file_url,f.original_name AS file_original_name,f.mime_type AS file_mime_type,f.size AS file_size FROM mensajes m LEFT JOIN users u ON u.id=m.user_id LEFT JOIN chat_message_features x ON x.message_id=m.id LEFT JOIN chat_message_users p ON p.message_id=m.id AND p.user_id=? LEFT JOIN chat_view_once v ON v.message_id=m.id LEFT JOIN files f ON f.id=m.file_id WHERE m.chat_id=? AND m.id<? AND p.hidden_at IS NULL'.$filter.' ORDER BY m.id DESC LIMIT '.($ids?'100':'60'),array_merge([$user,$chat,$before],$ids))->fetchAll(PDO::FETCH_ASSOC);
        $peers=(int)$this->q('SELECT COUNT(*) FROM chat_usuarios WHERE chat_id=?',[$chat])->fetchColumn()-1;
        $statsById=[];$reactionsById=[];$filesById=[];$repliesById=[];
        $pageIds=array_column($rows,'id');
        if($pageIds){
            $marks=implode(',',array_fill(0,count($pageIds),'?'));
            foreach($this->q('SELECT p.message_id,COUNT(p.delivered_at) AS delivered,COUNT(p.read_at) AS seen FROM chat_message_users p JOIN mensajes m ON m.id=p.message_id JOIN chat_usuarios c ON c.user_id=p.user_id AND c.chat_id=m.chat_id WHERE p.message_id IN ('.$marks.') AND p.user_id<>m.user_id GROUP BY p.message_id',$pageIds)->fetchAll(PDO::FETCH_ASSOC) as $r)$statsById[(int)$r['message_id']]=$r;
            foreach($this->q('SELECT message_id,reaction,COUNT(*) AS total FROM chat_message_users WHERE message_id IN ('.$marks.') AND reaction IS NOT NULL GROUP BY message_id,reaction',$pageIds)->fetchAll(PDO::FETCH_ASSOC) as $r)$reactionsById[(int)$r['message_id']][]=['reaction'=>$r['reaction'],'total'=>(int)$r['total']];
            $fileIds=array_values(array_unique(array_filter(array_column($rows,'attachment_id'))));
            if($fileIds)foreach($this->q('SELECT id,original_name,mime,size FROM chat_private_files WHERE id IN ('.implode(',',array_fill(0,count($fileIds),'?')).')',$fileIds)->fetchAll(PDO::FETCH_ASSOC) as $r)$filesById[(int)$r['id']]=$r;
            $replyIds=array_values(array_unique(array_filter(array_column($rows,'reply_to'))));
            if($replyIds)foreach($this->q('SELECT m.id,m.contenido,x.deleted_at,x.view_once FROM mensajes m LEFT JOIN chat_message_features x ON x.message_id=m.id WHERE m.chat_id=? AND m.id IN ('.implode(',',array_fill(0,count($replyIds),'?')).')',array_merge([$chat],$replyIds))->fetchAll(PDO::FETCH_ASSOC) as $r)$repliesById[(int)$r['id']]=$r;
        }
        foreach($rows as &$m){
            $id=(int)$m['id'];$owner=(int)$m['user_id'];
            $m['id']=$id;$m['user_id']=$owner;$m['view_once']=(bool)(int)$m['view_once'];$m['favorite']=(bool)(int)$m['favorite'];
            $stats=$statsById[$id]??['delivered'=>0,'seen'=>0];
            $m['delivered_at']=$peers>0&&(int)$stats['delivered']>=$peers?1:null;
            $m['read_at']=$peers>0&&(int)$stats['seen']>=$peers?1:null;
            $m['leido']=$m['read_at']?1:0;
            $m['reactions']=$reactionsById[$id]??[];
            if($m['attachment_id']&&!$m['view_once']&&!$m['deleted_at'])$m['attachment']=$filesById[(int)$m['attachment_id']]??null;
            if($m['reply_to']){
                $ref=$repliesById[(int)$m['reply_to']]??null;
                $m['reply']=$ref?['id'=>$ref['id'],'text'=>$ref['deleted_at']?'Mensaje eliminado':($ref['view_once']?'Visualización única':mb_substr($ref['contenido'],0,180))]:null;
            }
            if($m['view_once']){$m['contenido']='Mensaje de visualización única';$m['can_open']=$owner!==$user&&!$m['consumed_at']&&!$m['deleted_at'];}
            if($m['deleted_at']){$m['contenido']='Mensaje eliminado';$m['file_url']=null;$m['file_id']=null;$m['attachment']=null;$m['reply']=null;$m['tipo']='texto';}
        }unset($m);
        return ['messages'=>array_reverse($rows),'removed'=>array_values(array_diff($ids,array_column($rows,'id'))),'next_before'=>count($rows)===60?(int)end($rows)['id']:null];
    }
    public function notify(int $chat,int $actor,array $message,string $type='chat_message'): void {
        $this->q("INSERT INTO websocket_notifications(chat_id,user_id,message_type,message_data,status) VALUES(?,?,?,?,?)",[$chat,$actor,$type,json_encode($message,JSON_THROW_ON_ERROR),'pending']);
    }
    public function send(int $user,int $chat,array $data,?array $file=null): array {
        return $this->atomic(function()use($user,$chat,$data,$file){
            $this->lockChat($user,$chat);
            $key=(string)($data['request_key']??'');if(!preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$key))throw new InvalidArgumentException('Identificador de envío inválido');
            $text=trim((string)($data['body']??''));if(strlen($text)>16000||($text===''&&!$file&&!isset($data['forward_id'])))throw new InvalidArgumentException('Escribe un mensaje');
            $once=!empty($data['view_once']);$reply=(int)($data['reply_to']??0);$forward=(int)($data['forward_id']??0);
            $digest=hash('sha256',json_encode([$chat,$text,$once,$reply,$forward,$file['original_name']??null,$file['size']??null,$file['sha256']??null],JSON_THROW_ON_ERROR));
            $existing=$this->q('SELECT message_id,request_digest FROM chat_message_features WHERE request_owner=? AND request_key=?',[$user,$key])->fetch(PDO::FETCH_ASSOC);
            if($existing){if(!hash_equals($existing['request_digest'],$digest))throw new RuntimeException('Reintento con contenido distinto',409);return ['id'=>(int)$existing['message_id'],'chat_id'=>$chat,'duplicate'=>true];}
            $attachment=null;$legacyFile=null;$type='texto';
            if($reply){$r=$this->message($user,$reply);if((int)$r['chat_id']!==$chat||$r['view_once']||$r['deleted_at'])throw new InvalidArgumentException('No se puede responder a ese mensaje');}
            if($forward){
                $r=$this->message($user,$forward);if($r['view_once']||$r['deleted_at'])throw new InvalidArgumentException('No se puede reenviar');
                if($once)throw new InvalidArgumentException('No se puede reenviar como visualización única');
                // Copy legacy attachments to private storage at the controller boundary; never grant access to another chat's file URL.
                if($r['file_id'])throw new InvalidArgumentException('Descarga y adjunta el archivo para reenviarlo');
                $text=$r['contenido'];$attachment=$r['attachment_id'];$type=$r['tipo'];
            }
            if($file){
                if($once&&!preg_match('~^(image|audio|video)/~',$file['mime']))throw new InvalidArgumentException('Visualización única admite texto, imágenes, audio y video');
                $this->q('INSERT INTO chat_private_files(owner_id,storage_key,original_name,mime,size,created_at) VALUES(?,?,?,?,?,?)',[$user,$file['storage_key'],$file['original_name'],$file['mime'],$file['size'],time()]);
                $attachment=(int)$this->db->lastInsertId();$type=str_starts_with($file['mime'],'image/')?'imagen':(str_starts_with($file['mime'],'audio/')?'audio':'archivo');
            }
            $peers=$this->q('SELECT user_id FROM chat_usuarios WHERE chat_id=? AND user_id<>?',[$chat,$user])->fetchAll(PDO::FETCH_COLUMN);
            if($once&&count($peers)!==1)throw new InvalidArgumentException('Visualización única disponible en conversaciones de dos personas');
            $stored=$once?'Mensaje de visualización única':($text!==''?$text:'Archivo adjunto');
            $this->q('INSERT INTO mensajes(chat_id,user_id,contenido,tipo,file_id) VALUES(?,?,?,?,?)',[$chat,$user,$stored,$once?'texto':$type,$legacyFile]);
            $id=(int)$this->db->lastInsertId();
            $this->q('INSERT INTO chat_message_features(message_id,reply_to,forwarded_from,view_once,attachment_id,request_owner,request_key,request_digest) VALUES(?,?,?,?,?,?,?,?)',[$id,$reply?:null,$forward?:null,$once?1:0,$attachment,$user,$key,$digest]);
            if($once)(new ChatViewOnce($this->db))->store($id,$user,(int)$peers[0],json_encode(['text'=>$text,'attachment_id'=>$attachment],JSON_THROW_ON_ERROR),time());
            $this->q('UPDATE chats SET last_message_at=CURRENT_TIMESTAMP WHERE id=?',[$chat]);
            $this->notify($chat,$user,['id'=>$id,'message_id'=>$id,'chat_id'=>$chat,'user_id'=>$user,'contenido'=>$stored,'tipo'=>$once?'texto':$type,'feature_origin'=>true,'view_once'=>$once]);
            $devices=$this->q('SELECT d.user_id,d.fcm_token FROM device_tokens d JOIN chat_usuarios c ON c.user_id=d.user_id WHERE c.chat_id=? AND d.user_id<>? AND d.is_active=1',[$chat,$user])->fetchAll(PDO::FETCH_ASSOC);
            foreach($devices as $device)(new PushOutbox($this->db))->enqueue((int)$device['user_id'],$device['fcm_token'],['type'=>'new_message','message_id'=>(string)$id,'chat_id'=>(string)$chat,'body'=>$once?'Mensaje de visualización única':mb_substr($stored,0,160)],time());
            return ['id'=>$id,'chat_id'=>$chat];
        });
    }
    public function action(int $user,int $id,string $action,array $data=[]): array {
        return $this->atomic(function()use($user,$id,$action,$data){
            $m=$this->message($user,$id);$this->lockChat($user,(int)$m['chat_id']);$m=$this->message($user,$id);$own=(int)$m['user_id']===$user;
            if($action==='hide'){$this->putUser($id,$user,'hidden_at',time());return ['ok'=>true];}
            if($m['deleted_at'])throw new RuntimeException('Mensaje eliminado',410);
            if($action==='delete'||$action==='edit'){
                if(!$own)throw new RuntimeException('Solo el autor puede cambiar el mensaje',403);
                $this->ensureMeta($id);
                if($action==='edit'){
                    $text=trim((string)($data['body']??''));
                    if($m['view_once']||$m['file_id']||$m['attachment_id']||$text===''||strlen($text)>16000)throw new InvalidArgumentException('Este mensaje no se puede editar');
                    $this->q('UPDATE mensajes SET contenido=? WHERE id=?',[$text,$id]);$this->q('UPDATE chat_message_features SET edited_at=? WHERE message_id=?',[time(),$id]);
                    $this->discardPending($id);
                }else{
                    $this->q('UPDATE chat_message_features SET deleted_at=? WHERE message_id=?',[time(),$id]);
                    $this->q("UPDATE mensajes SET contenido='Mensaje eliminado',tipo='texto',file_id=NULL WHERE id=?",[$id]);
                    $this->q('UPDATE chat_view_once SET body=NULL,consumed_at=? WHERE message_id=?',[time(),$id]);
                    // Remove pending payloads so an unsent push does not disclose the deleted text.
                    $this->discardPending($id);
                }
            }elseif($action==='receipt'){
                if(!$own){$this->putUser($id,$user,'delivered_at',time());if(!empty($data['read'])&&!$m['view_once']){$this->putUser($id,$user,'read_at',time());$this->q('UPDATE mensajes SET leido=1 WHERE id=?',[$id]);}}
            }else{
                if($m['view_once'])throw new RuntimeException('Acción no disponible en visualización única',403);
                if($action==='favorite')$this->putUser($id,$user,'favorite',!empty($data['value'])?1:0);
                elseif($action==='reaction'){$emoji=$data['emoji']??null;if($emoji!==null&&!in_array($emoji,['👍','❤️','😂','😮','😢','🙏'],true))throw new InvalidArgumentException('Reacción inválida');$this->putUser($id,$user,'reaction',$m['reaction']===$emoji?null:$emoji);}
                elseif($action==='note'){
                    $body=trim((string)($data['body']??$m['contenido']));if($body===''||strlen($body)>16000)throw new InvalidArgumentException('Nota inválida');
                    try{$this->q('INSERT INTO chat_notes(owner_id,message_id,body,created_at) VALUES(?,?,?,?)',[$user,$id,$body,time()]);}catch(\PDOException $e){if((string)$e->getCode()!=='23000')throw $e;}
                }else throw new InvalidArgumentException('Acción inválida');
            }
            if(in_array($action,['delete','edit','reaction','receipt'],true))$this->notify((int)$m['chat_id'],$user,['message_id'=>$id,'chat_id'=>(int)$m['chat_id']],'chat_feature_update');
            return ['ok'=>true];
        });
    }
    private function discardPending(int $id): void {
        $expr=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?"JSON_UNQUOTE(JSON_EXTRACT(payload,'$.message_id'))":"CAST(json_extract(payload,'$.message_id') AS TEXT)";
        $this->q("UPDATE push_outbox SET status='expired',payload='{}' WHERE status='pending' AND $expr=?",[(string)$id]);
        $expr=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?"JSON_UNQUOTE(JSON_EXTRACT(message_data,'$.message_id'))":"CAST(json_extract(message_data,'$.message_id') AS TEXT)";
        $this->q("UPDATE websocket_notifications SET status='processed',message_data='{}' WHERE status='pending' AND $expr=?",[(string)$id]);
    }
    public function consume(int $user,int $id): array {
        $data=(new ChatViewOnce($this->db))->consume($user,$id,time(),function($viewer,$message){$m=$this->message($viewer,$message);if($m['deleted_at'])throw new RuntimeException('Mensaje eliminado',410);});
        $payload=json_decode($data,true,512,JSON_THROW_ON_ERROR);
        $this->putUser($id,$user,'read_at',time());$this->putUser($id,$user,'delivered_at',time());
        if(!empty($payload['attachment_id'])){
            $f=$this->fileRecord((int)$payload['attachment_id']);
            $path=self::mediaRoot().'/'.$f['storage_key'];$bytes=file_get_contents($path);
            if($bytes===false)throw new RuntimeException('Archivo no disponible',404);
            $payload['file']=['name'=>$f['original_name'],'mime'=>$f['mime'],'base64'=>base64_encode($bytes)];
            if(!unlink($path))error_log('CHAT_ONCE_MEDIA_CLEANUP_REQUIRED id='.(int)$f['id']);
        }unset($payload['attachment_id']);return $payload;
    }
    public static function mediaRoot(): string { return ($_ENV['CHAT_PRIVATE_MEDIA_DIR']??'')?:'/var/lib/tuanichat/chat-media'; }
    private function fileRecord(int $id): array { $f=$this->q('SELECT * FROM chat_private_files WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);if(!$f||!preg_match('/^[a-f0-9]{48}$/D',$f['storage_key']))throw new RuntimeException('Archivo no disponible',404);return $f; }
    public function media(int $user,int $id): array {
        $m=$this->message($user,$id);if($m['deleted_at']||$m['view_once']||!$m['attachment_id'])throw new RuntimeException('Archivo no disponible',404);return $this->fileRecord((int)$m['attachment_id']);
    }
    public function library(int $user,string $kind,int $before=PHP_INT_MAX): array {
        if($kind==='notes')return $this->q('SELECT id,message_id,body,created_at FROM chat_notes WHERE owner_id=? AND id<? ORDER BY id DESC LIMIT 50',[$user,$before])->fetchAll(PDO::FETCH_ASSOC);
        return $this->q('SELECT m.id,m.chat_id,m.contenido,m.enviado_en FROM chat_message_users p JOIN mensajes m ON m.id=p.message_id JOIN chat_usuarios c ON c.chat_id=m.chat_id AND c.user_id=p.user_id LEFT JOIN chat_message_features x ON x.message_id=m.id WHERE p.user_id=? AND p.favorite=1 AND p.hidden_at IS NULL AND x.deleted_at IS NULL AND COALESCE(x.view_once,0)=0 AND m.id<? ORDER BY m.id DESC LIMIT 50',[$user,$before])->fetchAll(PDO::FETCH_ASSOC);
    }
    public function deleteNote(int $user,int $id): void {$this->q('DELETE FROM chat_notes WHERE id=? AND owner_id=?',[$id,$user]);}
    public function activity(int $user,int $chat,?string $activity=null): array {
        $this->access($user,$chat);
        if($activity!==null){if(!in_array($activity,['typing','recording','idle'],true))throw new InvalidArgumentException('Actividad inválida');try{$this->q('INSERT INTO chat_activity(chat_id,user_id,activity,expires_at) VALUES(?,?,?,?)',[$chat,$user,$activity,time()+7]);}catch(\PDOException $e){if((string)$e->getCode()!=='23000')throw $e;$this->q('UPDATE chat_activity SET activity=?,expires_at=? WHERE chat_id=? AND user_id=?',[$activity,time()+7,$chat,$user]);}}
        return $this->q("SELECT user_id,activity FROM chat_activity WHERE chat_id=? AND user_id<>? AND expires_at>? AND activity<>'idle'",[$chat,$user,time()])->fetchAll(PDO::FETCH_ASSOC);
    }
}
