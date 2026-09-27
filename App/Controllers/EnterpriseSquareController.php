<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Configs\Database;
use App\Services\Enterprise\SquareInbox;
use EasyProjects\SimpleRouter\Router;
final class EnterpriseSquareController{
 public function productionStatus():mixed{
  try{
   $actor=(int)(Router::$request->user->id??0);$tenant=filter_var(Router::$request->params->tenant_id??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
   if(!$tenant)throw new \RuntimeException('invalid_tenant',400);
   $service=new \App\Services\Enterprise\SquareProductionStatus(new \App\Services\Enterprise\Core(Database::getInstance()->getConnection()));
   return Router::$response->status(200)->send(['success'=>true]+$service->status($tenant,$actor,time()));
  }catch(\Throwable $e){$code=(int)$e->getCode();if(!in_array($code,[400,403,404],true))$code=503;
   return Router::$response->status($code)->send(['success'=>false,'message'=>'No se pudo consultar la contratación de esta empresa.']);}
 }
 public function receiveProduction():mixed{
  try{
   $config=\App\Services\Enterprise\SquareProductionInbox::config();
   $raw=file_get_contents('php://input',false,null,0,1048577);
   $sig=$_SERVER['HTTP_X_SQUARE_HMACSHA256_SIGNATURE']??'';
   if(!is_string($raw)||strlen($raw)>1048576||!is_string($sig))throw new \RuntimeException('bad_request',400);
   $result=(new \App\Services\Enterprise\SquareProductionInbox(Database::getInstance()->getConnection()))->receive($raw,$sig,$config,time());
   return Router::$response->status(200)->send($result);
  }catch(\Throwable $e){$code=$e instanceof \JsonException?400:(int)$e->getCode();if(!in_array($code,[400,403,409,503],true))$code=503;
   return Router::$response->status($code)->send(['accepted'=>false]);}
 }
 public function checkout(string $action='status'):mixed{
  try{
   $actor=(int)(Router::$request->user->id??0);$tenant=filter_var(Router::$request->params->tenant_id??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
   if(!$tenant)throw new \RuntimeException('invalid_tenant',400);
   $core=new \App\Services\Enterprise\Core(Database::getInstance()->getConnection());
   $client=new \App\Services\Enterprise\SquareCheckoutClient();
   $service=new \App\Services\Enterprise\SquareCheckout($core,fn($method,$path,$body)=>$client->request($method,$path,$body));
   $result=$action==='status'?$service->status($tenant,$actor):$service->run($tenant,$actor,$action,time());
   return Router::$response->status(200)->send(['success'=>true]+$result);
  }catch(\Throwable $e){
   $code=(int)$e->getCode();if(!in_array($code,[400,403,404,409,429,503],true))$code=503;
   $message=match($e->getMessage()){
    'forbidden'=>'Solo el administrador de TuaniChat propietario de la empresa puede gestionar esta prueba.',
    'trial_not_linked'=>'Vincula primero la suscripción de prueba de esta empresa.',
    'checkout_wait','checkout_busy'=>'Hay una comprobación reciente o en curso. Espera un momento y vuelve a consultar.',
    'price_or_cadence_mismatch'=>'La tarifa de la prueba no coincide con la variación mensual de Square. Se requiere revisión.',
    default=>'No se pudo verificar la contratación. El enlace guardado se conserva; vuelve a consultar el estado.'};
   return Router::$response->status($code)->send(['success'=>false,'message'=>$message]);
  }
 }
 public function company(bool $link=false):mixed{
  try{
   $actor=(int)(Router::$request->user->id??0);$tenant=filter_var(Router::$request->params->tenant_id??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
   if(!$tenant)throw new \RuntimeException('invalid_tenant',400);
   $core=new \App\Services\Enterprise\Core(Database::getInstance()->getConnection());
   $reader=new \App\Services\Enterprise\SquareSandboxReader();
   $service=new \App\Services\Enterprise\SquareCompanySandbox($core,fn($path)=>$reader->get($path));
   if($link)$service->link($tenant,$actor,\App\Services\Enterprise\SquareCompanySandbox::candidate(),time());
   return Router::$response->status(200)->send(['success'=>true]+$service->status($tenant,$actor));
  }catch(\Throwable $e){$code=(int)$e->getCode();if(!in_array($code,[400,403,404,409,503],true))$code=503;
   $message=match($code){403=>'Solo el administrador de TuaniChat propietario de esta empresa puede vincular la prueba.',409=>'La suscripción de prueba ya está vinculada a otra empresa.',default=>'No se pudo consultar o vincular la prueba de Square.'};
   return Router::$response->status($code)->send(['success'=>false,'message'=>$message]);
  }
 }
 public function receive():mixed{
  try{
   $config=SquareInbox::config();
   $raw=file_get_contents('php://input',false,null,0,1048577);
   $signature=$_SERVER['HTTP_X_SQUARE_HMACSHA256_SIGNATURE']??'';
   if(!is_string($raw)||strlen($raw)>1048576||!is_string($signature))throw new \RuntimeException('bad_request',400);
   $result=(new SquareInbox(Database::getInstance()->getConnection()))->receive($raw,$signature,$config,time());
   return Router::$response->status(200)->send($result);
  }catch(\Throwable $e){
   $code=$e instanceof \JsonException?400:(int)$e->getCode();if(!in_array($code,[400,403,409,503],true))$code=503;
   return Router::$response->status($code)->send(['accepted'=>false]);
  }
 }
 public function status():mixed{
  try{$actor=(int)(Router::$request->user->id??0);$r=(new SquareInbox(Database::getInstance()->getConnection()))->status($actor);
   return Router::$response->status(200)->send(['success'=>true]+$r);
  }catch(\Throwable $e){return Router::$response->status($e->getCode()===403?403:503)->send(['success'=>false,'message'=>'No se pudo consultar Square Sandbox.']);}
 }
}
