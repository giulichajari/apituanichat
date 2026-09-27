<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Configs\Database;
use App\Services\Enterprise\{Core,BusinessRecords};
use EasyProjects\SimpleRouter\Router;
final class EnterpriseRecordsController
{
 public function approvals(bool $decide=false):mixed{
  try{
   $actor=(int)(Router::$request->user->id??0);if($actor<1)throw new \RuntimeException('Vuelve a iniciar sesión',401);
   $tenant=filter_var(Router::$request->params->tenant_id??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$tenant)throw new \InvalidArgumentException('Empresa inválida');
   $core=new Core(Database::getInstance()->getConnection());$core->authorize($tenant,$actor,'approve');
   if($decide){
    $b=Router::$request->body??null;
    if(isset($b->action)){
     $executor=new \App\Services\Enterprise\RecordApprovals($core);
     if($b->action==='request'&&is_string($b->record_id??null)&&is_int($b->version??null)&&is_int($b->agent_id??null))$result=$executor->request($tenant,$actor,$b->agent_id,$b->record_id,$b->version,time());
     elseif($b->action==='execute'&&is_string($b->id??null))$result=$executor->execute($tenant,$actor,$b->id,time());
     else throw new \InvalidArgumentException('Acción de revisión inválida');
     return Router::$response->status(200)->send(['success'=>true]+$result);
    }
    if(!is_string($b->id??null)||!preg_match('/^[a-f0-9]{32}$/D',$b->id)||!is_bool($b->approved??null))throw new \InvalidArgumentException('Decisión inválida');
    (new \App\Services\Enterprise\Approvals($core))->decide($tenant,$actor,$b->id,$b->approved,time());
    $result=['saved'=>true];
   }else{
    $rows=$core->query('SELECT id,event_id,tool,arguments_json,requested_by,decided_by,status,expires_at,created_at FROM ai_approvals WHERE tenant_id=? ORDER BY created_at DESC,id DESC LIMIT 50',[$tenant])->fetchAll(\PDO::FETCH_ASSOC);
    foreach($rows as &$row){$row['can_decide']=$row['status']==='pending'&&(int)$row['expires_at']>time()&&(int)$row['requested_by']!==$actor;$row['expired']=(int)$row['expires_at']<=time();}unset($row);
    $result=['approvals'=>(new \App\Services\Enterprise\RecordApprovals($core))->decorate($tenant,$actor,$rows,time())];
   }
   return Router::$response->status(200)->send(['success'=>true]+$result);
  }catch(\Throwable $e){
   $code=$e instanceof \InvalidArgumentException?400:(int)$e->getCode();if(!in_array($code,[400,401,402,403,404,409,429],true))$code=503;
   return Router::$response->status($code)->send(['success'=>false,'message'=>$code===503?'Aprobaciones no disponibles temporalmente.':$e->getMessage()]);
  }
 }
 public function handle(bool $save=false):mixed{
  try{
   $actor=(int)(Router::$request->user->id??0);if($actor<1)throw new \RuntimeException('Vuelve a iniciar sesión',401);
   $tenant=filter_var(Router::$request->params->tenant_id??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
   $kind=Router::$request->params->kind??null;if(!$tenant||!is_string($kind))throw new \InvalidArgumentException('Solicitud inválida');
   $s=new BusinessRecords(new Core(Database::getInstance()->getConnection()));
   if(!$save)$result=$s->listing($tenant,$actor,$kind);
   else{
    $b=Router::$request->body??null;
    if(!is_object($b)||!is_string($b->id??null)||!is_string($b->title??null)||!is_string($b->status??null)||!is_int($b->version??null)||!is_object($b->fields??null))throw new \InvalidArgumentException('Revisa el formulario');
    $result=$s->save($tenant,$actor,$b->id,$kind,$b->title,$b->status,(array)$b->fields,$b->version,time());
   }
   return Router::$response->status(200)->send(['success'=>true]+$result);
  }catch(\Throwable $e){
   $code=$e instanceof \InvalidArgumentException?400:(int)$e->getCode();if(!in_array($code,[400,401,403,404,409],true))$code=503;
   return Router::$response->status($code)->send(['success'=>false,'message'=>$code===503?'Gestión interna no disponible temporalmente.':$e->getMessage()]);
  }
 }
}
