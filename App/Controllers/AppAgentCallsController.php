<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Configs\Database;
use App\Services\Enterprise\{Core,AppAgentCalls,OpenAiSupport};
use EasyProjects\SimpleRouter\Router;
final class AppAgentCallsController {
 public function handle(string $action):mixed {
  try{
   $actor=(int)(Router::$request->user->id??0);if($actor<1)throw new \RuntimeException('Inicia sesión.',401);
   $body=Router::$request->body??(object)[];
   $text=static function(string $key)use($body):string {if(!is_string($body->{$key}??null))throw new \InvalidArgumentException('Falta '.$key);return $body->{$key};};
   $number=static function(string $key)use($body):int {if(!is_int($body->{$key}??null)||$body->{$key}<1)throw new \InvalidArgumentException('Identificador inválido.');return $body->{$key};};
   $s=new AppAgentCalls(new Core(Database::getInstance()->getConnection()),static function($e,$m,$k,$mem,$profile){
    $p=new OpenAiSupport(trim((string)($_ENV['OPENAI_API_KEY']??'')),trim((string)($_ENV['OPENAI_MODEL']??'')));
    return $p->propose($e,$m,$k,$mem,$profile,true,null,true);
   });
   $now=time();
   $result=match($action){
    'voice-start','voice-poll','voice-stop','voice-ready'=>(new \App\Services\Enterprise\AppAgentVoice(new Core(Database::getInstance()->getConnection())))->handle(substr($action,6),$actor,$text('call_id'),$text('request_id'),$action==='voice-start'?$text('sdp'):''),
    'owner'=>$s->listing((int)(Router::$request->params->tenant_id??0),$actor,$now),
    'inbox'=>$s->inbox($actor,$now),
    'detail'=>$s->detail((string)(Router::$request->params->call_id??''),$actor,$now),
    'create'=>['call'=>$s->create($number('tenant_id'),$actor,$number('agent_id'),$number('recipient_id'),$text('call_id'),$text('purpose'),$text('objective'),$text('boundaries'),$now)],
    'action'=>$s->action($text('call_id'),$actor,$text('action'),$now),
    'turn'=>$s->turn($text('call_id'),$actor,$text('turn_id'),$text('message'),$now),
    default=>throw new \InvalidArgumentException('Acción inválida.')
   };
   return Router::$response->status(200)->send(['success'=>true]+$result);
  }catch(\Throwable $e){
   $code=$e instanceof \InvalidArgumentException?400:(int)$e->getCode();
   if(!in_array($code,[400,401,403,404,409,429],true))$code=503;
   return Router::$response->status($code)->send(['success'=>false,'message'=>$code===503?'No se pudo consultar la conversación. Intenta actualizar.':$e->getMessage()]);
  }
 }
}
