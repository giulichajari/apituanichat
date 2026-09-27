<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
use PDO;
use RuntimeException;

/** Plans a route only. Business tool execution is a separate approved step. */
final class EnterpriseDispatch
{
 public const EVENT='AGENT_ROUTE_REQUEST';
 private const MODULES=['director','sales','finance','operations','hr','support','marketing','reservations','commerce','logistics'];
 public function __construct(private Core $core,private Events $events){}
 public function enqueue(int $tenant,int $actor,int $agent,string $key,int $now):array{
  $c=$this->core;$c->authorize($tenant,$actor,'execute');
  $module=$c->query('SELECT module FROM ai_agents WHERE tenant_id=? AND id=?',[$tenant,$agent])->fetchColumn();
  if(!in_array($module,self::MODULES,true))throw new RuntimeException('Función no disponible',404);
  return $this->events->enqueue($tenant,$actor,$agent,self::EVENT,[],$key,$now);
 }
 public function once(callable $send,callable $clock):array{
  // This worker must never consume another integration's queue.
  $row=$this->events->claim($clock(),[self::EVENT]);
  if(!$row)return ['status'=>'idle'];
  $tenant=(int)$row['tenant_id'];$id=$row['id'];$lease=$row['lease_key'];
  try{
   $c=$this->core;
   $module=$c->query('SELECT module FROM ai_agents WHERE tenant_id=? AND id=?',[$tenant,$row['agent_id']])->fetchColumn();
   if(!in_array($module,self::MODULES,true))throw new RuntimeException('Función no disponible');
   $request=['schema_version'=>1,'event_id'=>$id,'tenant_id'=>$tenant,'actor_id'=>(int)$row['actor_id'],'agent_id'=>(int)$row['agent_id'],'event_type'=>strtoupper($module).'_REQUEST','module'=>$module,'correlation_id'=>$row['correlation_id'],'payload'=>(object)[]];
   // No instructions, customer data, secrets or arbitrary destinations leave the server.
   $reply=$send($request);
   self::validateReply($request,$reply);
   // A changed agent configuration invalidates this routing result.
   $c->transaction(function()use($c,$tenant,$row,$module,$id,$lease,$clock){
    $c->lock($tenant);
    $current=$c->query('SELECT module FROM ai_agents WHERE tenant_id=? AND id=?',[$tenant,$row['agent_id']])->fetchColumn();
    if($current!==$module)throw new RuntimeException('Función modificada');
    $this->events->finish($tenant,$id,$lease,['outcome'=>'route_selected','module'=>$module,'business_action_executed'=>false],$clock());
   });
   return ['status'=>'route_selected','event_id'=>$id,'business_action_executed'=>false];
  }catch(\Throwable $e){
   // Never repeat an ambiguous request automatically, even if the current adapter is read-only.
   try{$this->events->fail($tenant,$id,$lease,$clock(),true);}catch(\Throwable $ignored){
    // Expired leases are quarantined by claim on the next run; not falsely reported as handled.
    return ['status'=>'reconciliation_pending','event_id'=>$id];
   }
   return ['status'=>'needs_review','event_id'=>$id];
  }
 }
 public static function validateReply(array $request,mixed $reply):void{
  if(!is_array($reply)||array_is_list($reply))throw new RuntimeException('Respuesta inválida');
  $expected=['schema_version'=>1,'event_id'=>$request['event_id'],'tenant_id'=>$request['tenant_id'],'actor_id'=>$request['actor_id'],'agent_id'=>$request['agent_id'],'correlation_id'=>$request['correlation_id'],'module'=>$request['module'],'status'=>'planned_only','external_actions'=>0,'authorization_required'=>true];
  ksort($expected);ksort($reply);
  if($reply!==$expected)throw new RuntimeException('Respuesta ajena o incompatible');
 }
}
