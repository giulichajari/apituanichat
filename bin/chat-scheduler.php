<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
require dirname(__DIR__).'/vendor/autoload.php';
\Dotenv\Dotenv::createImmutable(dirname(__DIR__))->load();
$db=\App\Configs\Database::getInstance()->getConnection();
if(($argv[1]??'')==='--check'){
    foreach(['chat_scheduled_tasks','chat_message_features','chat_call_history'] as $table)$db->query('SELECT 1 FROM '.$table.' LIMIT 1');
    if(!is_writable(\App\Services\ChatFeatures::mediaRoot()))throw new RuntimeException('Directorio privado no escribible');
    echo "OK: worker, base de datos y almacenamiento privado disponibles.\n";exit;
}
$features=new \App\Services\ChatFeatures($db);$scheduler=new \App\Services\ChatSchedule($db);
$authorize=function(int $owner,?int $chat,string $kind)use($db,$features){
    $q=$db->prepare('SELECT id FROM users WHERE id=?');$q->execute([$owner]);if(!$q->fetchColumn())throw new RuntimeException('Cuenta no disponible',403);
    if($kind==='message')$features->access($owner,(int)$chat);
};
$deliver=function(PDO $db,array $task)use($features):int{
    if($task['kind']==='message'){
        $result=$features->send((int)$task['owner_id'],(int)$task['chat_id'],['body'=>$task['body'],'request_key'=>'scheduled_task_'.str_pad((string)$task['id'],16,'0',STR_PAD_LEFT)]);return $result['id'];
    }
    $q=$db->prepare('SELECT fcm_token FROM device_tokens WHERE user_id=? AND is_active=1');$q->execute([$task['owner_id']]);$tokens=$q->fetchAll(PDO::FETCH_COLUMN);
    foreach($tokens as $token)(new \App\Services\PushOutbox($db))->enqueue((int)$task['owner_id'],$token,['type'=>'reminder','event_id'=>(string)$task['id'],'title'=>'Recordatorio de agenda','body'=>mb_substr($task['body'],0,240)],time());
    return (int)$task['id'];
};
$failures=0;
for($i=0;$i<30;$i++){
    try{if(!$scheduler->runOne(time(),$authorize,$deliver))break;}
    catch(Throwable $e){++$failures;error_log('CHAT_SCHEDULER '.get_class($e).' code='.$e->getCode());}
}
exit($failures?1:0);
