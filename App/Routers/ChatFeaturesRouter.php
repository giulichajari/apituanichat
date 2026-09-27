<?php
declare(strict_types=1);
namespace App\Routers;
use App\Configs\Database;
use App\Middlewares\TokenMiddleware;
use App\Services\{ChatFeatures,ChatSchedule,ChatCallHistory};
use EasyProjects\SimpleRouter\Router;

final class ChatFeaturesRouter
{
    public function __construct(Router $router) {
        $auth=new TokenMiddleware();
        $router->post('/chat-features',fn()=>$auth->strict(),fn()=>$this->dispatch());
        $router->get('/chat-features/media',fn()=>$auth->strict(),fn()=>$this->media());
    }
    private function user(): int {return (int)(Router::$request->user->id??0);}
    private function headers(): void {header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');}
    private function fail(\Throwable $e): void {
        $code=$e instanceof \InvalidArgumentException?400:(int)$e->getCode();
        if(!in_array($code,[400,403,404,409,410],true)){$code=500;error_log('CHAT_FEATURES '.get_class($e).' code='.$e->getCode().' line='.$e->getLine());}
        Router::$response->status($code)->send(['message'=>$code===500?'No se pudo completar la operación':$e->getMessage()]);
    }
    private function upload(): ?array {
        if(empty($_FILES['file']))return null;
        $f=$_FILES['file'];
        if($f['error']!==UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) || $f['size']<1 || $f['size']>20*1024*1024)throw new \InvalidArgumentException('Archivo inválido; máximo 20 MB');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        $allowed=['image/jpeg','image/png','image/webp','image/gif','video/mp4','video/webm','video/quicktime','audio/webm','audio/ogg','audio/mpeg','audio/mp4','audio/wav','audio/x-wav','application/pdf','text/plain','application/zip','application/gzip','application/x-gzip','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/vnd.ms-excel'];
        if(!in_array($mime,$allowed,true))throw new \InvalidArgumentException('Formato no admitido');
        // WebM/MP4 audio containers may be identified as video by libmagic.
        if(($_POST['recording']??'')==='1' && in_array($mime,['video/webm','video/mp4'],true))$mime=str_replace('video/','audio/',$mime);
        $root=ChatFeatures::mediaRoot();if(!is_dir($root)||!is_writable($root))throw new \RuntimeException('Almacenamiento privado no disponible');
        $key=bin2hex(random_bytes(24));$sha=hash_file('sha256',$f['tmp_name']);
        if(!move_uploaded_file($f['tmp_name'],$root.'/'.$key))throw new \RuntimeException('No se pudo guardar el archivo');
        chmod($root.'/'.$key,0640);
        return ['storage_key'=>$key,'original_name'=>mb_substr(preg_replace('/[\x00-\x1f\x7f\\\\\/]/u','_',basename($f['name'])),0,220),'mime'=>$mime,'size'=>(int)$f['size'],'sha256'=>$sha];
    }
    private function dispatch(): void {
        $this->headers();$upload=null;$saved=false;
        try {
            $db=Database::getInstance()->getConnection();$service=new ChatFeatures($db);$user=$this->user();
            $d=$_POST?:((array)(Router::$request->body??[]));$action=(string)($d['action']??'');
            $id=(int)($d['id']??0);$chat=(int)($d['chat_id']??0);$before=(int)($d['before']??PHP_INT_MAX);
            $schedule=new ChatSchedule($db);
            $authorize=function(int $owner,?int $chat,string $kind)use($service){if($kind==='message')$service->access($owner,(int)$chat);};
            switch($action){
                case 'ensure':$result=['chat_id'=>$service->ensure($user,(int)($d['peer_id']??0))];break;
                case 'list':$result=$service->list($user,$chat,$before,(array)($d['ids']??[]));break;
                case 'send':
                    $service->access($user,$chat);$upload=$this->upload();$result=$service->send($user,$chat,$d,$upload);
                    $saved=empty($result['duplicate']);break;
                case 'hide':case 'delete':case 'edit':case 'reaction':case 'favorite':case 'note':case 'receipt':$result=$service->action($user,$id,$action,$d);break;
                case 'consume':$result=$service->consume($user,$id);break;
                case 'library':$result=['items'=>$service->library($user,(string)($d['kind']??'favorites'),$before)];break;
                case 'delete_note':$service->deleteNote($user,$id);$result=['ok'=>true];break;
                case 'activity':$result=['items'=>$service->activity($user,$chat,isset($d['activity'])?(string)$d['activity']:null)];break;
                case 'schedule':$result=$schedule->create($user,(string)($d['request_key']??''),$d,time(),$authorize);break;
                case 'tasks':$result=['items'=>$schedule->list($user,$before,$d['kind']??null)];break;
                case 'reminders':$result=['items'=>$schedule->reminders($user,time())];break;
                case 'reminder_ack':$schedule->acknowledge($user,$id,time());$result=['ok'=>true];break;
                case 'task_edit':$result=$schedule->change($user,$id,$d,time(),$authorize);break;
                case 'task_cancel':$result=$schedule->cancel($user,$id,time());break;
                case 'calls':$result=['items'=>(new ChatCallHistory($db))->list($user,$before,(string)($d['before_call']??''))];break;
                default:throw new \InvalidArgumentException('Acción no disponible');
            }
            Router::$response->status(200)->send($result);
        }catch(\Throwable $e){$this->fail($e);}
        finally{if($upload&&!$saved){$path=ChatFeatures::mediaRoot().'/'.$upload['storage_key'];if(is_file($path))unlink($path);}}
    }
    private function media(): void {
        $this->headers();
        try {
            $f=(new ChatFeatures(Database::getInstance()->getConnection()))->media($this->user(),(int)($_GET['id']??0));
            $path=ChatFeatures::mediaRoot().'/'.$f['storage_key'];if(!is_file($path))throw new \RuntimeException('Archivo no disponible',404);
            header('Content-Type: '.$f['mime']);header('Content-Length: '.filesize($path));
            header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode($f['original_name']));
            readfile($path);exit;
        }catch(\Throwable $e){$this->fail($e);}
    }
}
