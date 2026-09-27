<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Configs\Database;
use App\Services\Enterprise\{Core,Events,BusinessRecords,RecordJobs};
use EasyProjects\SimpleRouter\Router;
final class EnterpriseRecordJobsController
{
 public function handle(bool $create=false):mixed{
  try{
   $actor=(int)(Router::$request->user->id??0);if($actor<1)throw new \RuntimeException('Vuelve a iniciar sesión',401);
   $tenant=filter_var(Router::$request->params->tenant_id??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$tenant)throw new \InvalidArgumentException('Empresa inválida');
   $c=new Core(Database::getInstance()->getConnection());$s=new RecordJobs($c,new Events($c),new BusinessRecords($c));
   if(!$create)$result=$s->listing($tenant,$actor);
   else{
    $b=Router::$request->body??null;
    if(!is_object($b)||!is_int($b->agent_id??null)||$b->agent_id<1||!is_string($b->request_key??null)||!is_object($b->record??null)||!is_object($b->record->fields??null))throw new \InvalidArgumentException('Revisa los datos de la tarea');
    $p=(array)$b->record;$p['fields']=(array)$b->record->fields;
    $parent=$b->after_event_id??null;
    if($parent!==null&&(!is_string($parent)||!preg_match('/^[a-f0-9]{32}$/D',$parent)))throw new \InvalidArgumentException('Dependencia inválida');
    $event=$s->enqueue($tenant,$actor,$b->agent_id,$p,$b->request_key,time(),$parent);
    $result=['event_id'=>$event['id'],'status'=>$event['status']];
   }
   return Router::$response->status(200)->send(['success'=>true]+$result);
  }catch(\Throwable $e){
   $code=$e instanceof \InvalidArgumentException?400:(int)$e->getCode();if(!in_array($code,[400,401,402,403,404,409,429],true))$code=503;
   return Router::$response->status($code)->send(['success'=>false,'message'=>$code===503?'No se pudo procesar la tarea. Conserva los datos y vuelve a consultar su estado.':$e->getMessage()]);
  }
 }
}
