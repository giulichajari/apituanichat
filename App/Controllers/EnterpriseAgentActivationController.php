<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Configs\Database;
use App\Services\Enterprise\{Core,AgentActivation};
use EasyProjects\SimpleRouter\Router;
final class EnterpriseAgentActivationController {
 public function handle(bool $write=false):mixed{
  try{
   $actor=(int)(Router::$request->user->id??0);if($actor<1)throw new \RuntimeException('Vuelve a iniciar sesión',401);
   $tenant=filter_var(Router::$request->params->tenant_id??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$tenant)throw new \InvalidArgumentException('Empresa inválida');
   $s=new AgentActivation(new Core(Database::getInstance()->getConnection()));
   if($write){$b=Router::$request->body??null;if(!is_object($b))throw new \InvalidArgumentException('Solicitud inválida');
    if(($b->action??null)==='activation'){
     if(!is_int($b->agent_id??null)||$b->agent_id<1||!is_bool($b->enabled??null)||!is_string($b->digest??null)||!preg_match('/^[a-f0-9]{64}$/D',$b->digest))throw new \InvalidArgumentException('Revisa los datos del agente');
     $s->toggle($tenant,$actor,$b->agent_id,$b->enabled,$b->digest,time());
    }elseif(($b->action??null)==='quota'){
     if(!is_int($b->limit??null)||!property_exists($b,'expected')||($b->expected!==null&&!is_int($b->expected)))throw new \InvalidArgumentException('Revisa la cuota');
     $s->setQuota($tenant,$actor,$b->limit,$b->expected,time());
    }else throw new \InvalidArgumentException('Acción inválida');
   }
   return Router::$response->status(200)->send(['success'=>true]+$s->listing($tenant,$actor,time()));
  }catch(\Throwable $e){$code=$e instanceof \InvalidArgumentException?400:(int)$e->getCode();if(!in_array($code,[400,401,402,403,404,409,429],true))$code=503;return Router::$response->status($code)->send(['success'=>false,'message'=>$code===503?'No se pudo actualizar. Consulta el estado antes de reintentar.':$e->getMessage()]);}
 }
}
