<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Configs\Database;
use App\Services\Enterprise\{Core,ConsoleService};
use EasyProjects\SimpleRouter\Router;
final class EnterpriseController
{
 private function core():Core{return new Core(Database::getInstance()->getConnection());}
 private function actor():int{$id=(int)(Router::$request->user->id??0);if($id<1)throw new \RuntimeException('Sesión requerida',401);return $id;}
 private function tenant():int{$id=filter_var(Router::$request->params->tenant_id??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$id)throw new \InvalidArgumentException('Empresa inválida');return $id;}
 private function text(string $key,int $max):string{$v=Router::$request->body->{$key}??null;if(!is_string($v)||strlen($v)>$max)throw new \InvalidArgumentException('Campo inválido: '.$key);return trim($v);}
 private function output(callable $action):mixed{
  try{return Router::$response->status(200)->send(['success'=>true]+$action());}
  catch(\Throwable $e){$code=$e instanceof \InvalidArgumentException?400:(int)$e->getCode();if(!in_array($code,[400,401,402,403,404,409,429],true))$code=503;
   return Router::$response->status($code)->send(['success'=>false,'message'=>match($code){400=>'Revisa los campos introducidos.',401=>'Vuelve a iniciar sesión.',402=>'La empresa no tiene una suscripción vigente.',403=>'No tienes permiso para esta operación.',404=>'Empresa, agente o usuario no disponible.',409=>'El registro cambió. Actualiza antes de guardar.',429=>'Se alcanzó el límite configurado.',default=>'El panel empresarial no está disponible temporalmente.'}]);}
 }
 public function supportJoin(bool $join=false):mixed{return $this->output(function()use($join){$s=new \App\Services\Enterprise\CompanySupportClients(Database::getInstance()->getConnection(),(int)($_ENV['BOT_USER_ID']??0));return $join?$s->join($this->tenant(),$this->actor(),time()):$s->info($this->tenant(),$this->actor());});}
 public function supportInbox(bool $review=false):mixed{return $this->output(function()use($review){
  $s=new \App\Services\Enterprise\CompanySupportInbox($this->core());
  return $review?$s->review($this->tenant(),$this->actor(),$this->text('job_id',32),time()):$s->read($this->tenant(),$this->actor(),time());
 });}
 public function supportTicket(bool $resolve=false):mixed{return $this->output(function()use($resolve){
  $db=Database::getInstance()->getConnection();$s=\App\Services\Enterprise\SupportRuntime::tickets($db);
  $result=$resolve?$s->resolve($this->tenant(),$this->actor(),$this->text('job_id',32),time()):$s->reply($this->tenant(),$this->actor(),$this->text('job_id',32),$this->text('reply',4000),time());
  if(!$resolve){try{\App\Services\Enterprise\SupportRuntime::delivery($db)->flush(\Closure::fromCallable([\App\Services\Enterprise\SupportRuntime::class,'broadcast']));}catch(\Throwable $e){/* Durable notice is retried by the existing worker. */}}
  return $result;
 });}
 public function profile(bool $save=false):mixed{return $this->output(function()use($save){
  $s=new \App\Services\Enterprise\CompanyProfile($this->core());
  if(!$save)return ['profile'=>$s->read($this->tenant(),$this->actor())];
  $v=Router::$request->body->version??null;$fields=Router::$request->body->fields??null;
  if(!is_int($v)||!is_object($fields))throw new \InvalidArgumentException('Ficha inválida');
  return ['profile'=>$s->save($this->tenant(),$this->actor(),(array)$fields,$v,time())];
 });}
 public function tasks(string $action='list'):mixed{return $this->output(function()use($action){
  $core=$this->core();$preview=new \App\Services\Enterprise\AgentPreview($core,static function($e,$m,$k,$mem,$profile){
   $provider=new \App\Services\Enterprise\OpenAiSupport(trim((string)($_ENV['OPENAI_API_KEY']??'')),trim((string)($_ENV['OPENAI_MODEL']??'')));
   return $provider->propose($e,$m,$k,$mem,$profile,true,$e['preview_module']??null);
  });
  $service=new \App\Services\Enterprise\AgentTasks($core,$preview);$tenant=$this->tenant();$actor=$this->actor();$now=time();
  if($action==='list')return $service->listing($tenant,$actor,$now);
  $id=$this->text('task_id',32);
  if($action==='create'){$agent=Router::$request->body->agent_id??null;if(!is_int($agent)||$agent<1)throw new \InvalidArgumentException('Agente inválido');return ['task'=>$service->create($tenant,$actor,$agent,$id,$this->text('title',120),$this->text('brief',2400),$now)];}
  if($action==='generate')return ['task'=>$service->generate($tenant,$actor,$id,$now)];
  $version=Router::$request->body->version??null;$review=Router::$request->body->review??null;
  if(!is_int($version)||!is_bool($review))throw new \InvalidArgumentException('Revisión inválida');
  return ['task'=>$service->save($tenant,$actor,$id,$this->text('draft',8000),$version,$review,$now)];
 });}
 public function preview(bool $run=false):mixed{return $this->output(function()use($run){
  $service=new \App\Services\Enterprise\AgentPreview($this->core(),static function($event,$message,$knowledge,$memory,$profile){
   $provider=new \App\Services\Enterprise\OpenAiSupport(trim((string)($_ENV['OPENAI_API_KEY']??'')),trim((string)($_ENV['OPENAI_MODEL']??'')));
   return $provider->propose($event,$message,$knowledge,$memory,$profile,true,$event['preview_module']??null);
  });
  if(!$run)return $service->status($this->tenant(),$this->actor(),time());
  $agent=Router::$request->body->agent_id??null;if(!is_int($agent)||$agent<1)throw new \InvalidArgumentException('Agente inválido');
  return ['preview'=>$service->run($this->tenant(),$this->actor(),$agent,$this->text('request_id',32),$this->text('message',3000),time())];
 });}
 public function list():mixed{return $this->output(fn()=>['companies'=>$this->core()->tenants($this->actor())]);}
 public function create():mixed{return $this->output(function(){$c=$this->core();return ['company'=>$c->createTenant($this->actor(),$this->text('name',120),time(),fn($id)=>(bool)$c->query('SELECT id FROM users WHERE id=?',[$id])->fetchColumn())];});}
 public function dashboard():mixed{return $this->output(fn()=>(new ConsoleService($this->core()))->dashboard($this->tenant(),$this->actor(),time()));}
 public function member():mixed{return $this->output(function(){
  $active=Router::$request->body->active??null;if(!is_bool($active))throw new \InvalidArgumentException('Estado inválido');
  (new ConsoleService($this->core()))->member($this->tenant(),$this->actor(),$this->text('email',254),$this->text('role',20),$active,time());return ['saved'=>true];
 });}
 public function agent():mixed{return $this->output(function(){
  $id=Router::$request->body->id??null;if($id!==null&&(!is_int($id)||$id<1))throw new \InvalidArgumentException('Agente inválido');
  return ['agent_id'=>(new ConsoleService($this->core()))->agent($this->tenant(),$this->actor(),$id,$this->text('name',100),$this->text('module',40),$this->text('instructions',12000),time())];
 });}
 public function memoryRead():mixed{return $this->output(fn()=>['records'=>$this->core()->recall($this->tenant(),$this->actor(),$this->text('customer',100),time())]);}
 public function price():mixed{return $this->output(function(){
  $b=Router::$request->body;foreach(['monthly_cents','call_cents','version'] as $k)if(!is_int($b->{$k}??null))throw new \InvalidArgumentException('Precio inválido');
  (new ConsoleService($this->core()))->price($this->actor(),$b->monthly_cents,$b->call_cents,$b->version,time());return ['saved'=>true];
 });}
 public function memoryWrite():mixed{return $this->output(function(){
  $b=Router::$request->body;if(!is_int($b->version??null)||$b->version<0||!is_int($b->retention_days??null)||$b->retention_days<1||$b->retention_days>365)throw new \InvalidArgumentException('Versión o retención inválida');
  return ['version'=>$this->core()->remember($this->tenant(),$this->actor(),$this->text('customer',100),$this->text('key',100),['note'=>$this->text('note',8000)],$b->version,time()+$b->retention_days*86400,time())];
 });}
}
