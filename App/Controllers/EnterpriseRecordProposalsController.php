<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Configs\Database;
use App\Services\Enterprise\{Core,Events,BusinessRecords,RecordJobs,RecordProposals,RecordProposalProvider};
use EasyProjects\SimpleRouter\Router;
final class EnterpriseRecordProposalsController {
 public function handle(string $action='list'):mixed {
  try{
   $actor=(int)(Router::$request->user->id??0);if($actor<1)throw new \RuntimeException('Vuelve a iniciar sesión',401);
   $tenant=filter_var(Router::$request->params->tenant_id??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$tenant)throw new \InvalidArgumentException('Empresa inválida');
   $core=new Core(Database::getInstance()->getConnection());$records=new BusinessRecords($core);
   $service=new RecordProposals($core,new RecordJobs($core,new Events($core),$records),$records);
   if($action==='list')$result=$service->listing($tenant,$actor,time());
   else{
    $b=Router::$request->body??null;
    if(!is_object($b)||!is_string($b->id??null)||!preg_match('/^[a-f0-9]{32}$/D',$b->id))throw new \InvalidArgumentException('Solicitud inválida');
    if($action==='generate'){
     if(!is_int($b->agent_id??null)||$b->agent_id<1||!is_string($b->brief??null))throw new \InvalidArgumentException('Revisa el agente y el encargo');
     $result=['proposal'=>$service->generate($tenant,$actor,$b->agent_id,$b->id,$b->brief,static function(...$args){
      $provider=new RecordProposalProvider(trim((string)($_ENV['OPENAI_API_KEY']??'')),trim((string)($_ENV['OPENAI_MODEL']??'')));
      return $provider->generate(...$args);
     },static fn()=>time())];
    }elseif($action==='accept'){
     if(!is_string($b->digest??null)||!preg_match('/^[a-f0-9]{64}$/D',$b->digest))throw new \InvalidArgumentException('Revisa la propuesta');
     $parent=$b->after_event_id??null;
     if($parent!==null&&(!is_string($parent)||!preg_match('/^[a-f0-9]{32}$/D',$parent)))throw new \InvalidArgumentException('Dependencia inválida');
     $result=['proposal'=>$service->accept($tenant,$actor,$b->id,$b->digest,time(),$parent)];
    }else throw new \InvalidArgumentException('Acción inválida');
   }
   return Router::$response->status(200)->send(['success'=>true]+$result);
  }catch(\Throwable $e){
   $code=$e instanceof \InvalidArgumentException?400:(int)$e->getCode();if(!in_array($code,[400,401,402,403,404,409,429],true))$code=503;
   return Router::$response->status($code)->send(['success'=>false,'message'=>$code===503?'No se pudo completar la solicitud. Actualiza las propuestas antes de volver a intentarlo.':$e->getMessage()]);
  }
 }
}
