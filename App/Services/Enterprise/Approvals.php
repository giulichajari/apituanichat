<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
use PDO;
use RuntimeException;
use InvalidArgumentException;
/** Approval does not execute a tool. Provider adapters must revalidate and reconcile results. */
final class Approvals
{
 private const TOOLS=['record.finalize_internal','payment.create','call.outbound','message.external','record.delete','hr.decision'];
 public function __construct(private Core $core){}
 public function request(int $tenant,int $actor,string $event,string $tool,array $arguments,int $expires,int $now): string{
  if(!in_array($tool,self::TOOLS,true)||$expires<=$now||$expires>$now+86400)throw new InvalidArgumentException('Aprobación inválida');$json=Core::json($arguments);
  return $this->core->transaction(function()use($tenant,$actor,$event,$tool,$json,$expires,$now){
   $c=$this->core;$c->lock($tenant);$c->authorize($tenant,$actor,'execute');$c->entitlement($tenant,$now);
   if(!$c->query('SELECT id FROM ai_events WHERE tenant_id=? AND id=? AND actor_id=?',[$tenant,$event,$actor])->fetchColumn())throw new RuntimeException('Evento no disponible',404);
   $id=bin2hex(random_bytes(16));$c->query("INSERT INTO ai_approvals(id,tenant_id,event_id,tool,arguments_json,arguments_hash,requested_by,status,expires_at,created_at) VALUES(?,?,?,?,?,?,?,'pending',?,?)",[$id,$tenant,$event,$tool,$json,hash('sha256',$json),$actor,$expires,$now]);
   $c->audit($tenant,$actor,'approval.requested',$id,['event_id'=>$event,'digest'=>hash('sha256',$json)],$now);return $id;
  });
 }
 public function decide(int $tenant,int $actor,string $id,bool $approved,int $now): void{
  $this->core->transaction(function()use($tenant,$actor,$id,$approved,$now){
   $c=$this->core;$c->lock($tenant);$c->authorize($tenant,$actor,'approve');$c->entitlement($tenant,$now);
   $row=$c->query('SELECT * FROM ai_approvals WHERE tenant_id=? AND id=?',[$tenant,$id])->fetch(PDO::FETCH_ASSOC);
   if(!$row)throw new RuntimeException('Aprobación no disponible',404);
   if((int)$row['requested_by']===$actor)throw new RuntimeException('Debe aprobar otra persona autorizada',403);
   if($row['status']!=='pending'||(int)$row['expires_at']<=$now)throw new RuntimeException('Aprobación cerrada o vencida',409);
   $status=$approved?'approved':'rejected';$c->query('UPDATE ai_approvals SET status=?,decided_by=?,decided_at=? WHERE tenant_id=? AND id=?',[$status,$actor,$now,$tenant,$id]);
   $c->audit($tenant,$actor,'approval.decided',$id,['status'=>$status],$now);
  });
 }
 /** Consume inside the SAME local transaction that durably queues the approved command. */
 public function consume(int $tenant,int $actor,string $id,string $tool,array $arguments,int $now,callable $enqueueCommand): mixed{
  $json=Core::json($arguments);
  return $this->core->transaction(function()use($tenant,$actor,$id,$tool,$json,$now,$enqueueCommand){
   $c=$this->core;$c->lock($tenant);$c->authorize($tenant,$actor,'execute');$c->entitlement($tenant,$now);
   $row=$c->query('SELECT * FROM ai_approvals WHERE tenant_id=? AND id=?',[$tenant,$id])->fetch(PDO::FETCH_ASSOC);
   if(!$row||$row['status']!=='approved'||(int)$row['expires_at']<=$now||(int)$row['requested_by']!==$actor||$row['tool']!==$tool||!hash_equals($row['arguments_hash'],hash('sha256',$json)))throw new RuntimeException('La autorización no cubre esta acción',403);
   $c->authorize($tenant,(int)$row['decided_by'],'approve');
   $result=$enqueueCommand($row); // Must not contact remote services in this callback.
   $c->query("UPDATE ai_approvals SET status='consumed' WHERE tenant_id=? AND id=?",[$tenant,$id]);
   $c->audit($tenant,$actor,'approval.consumed',$id,['status'=>'consumed'],$now);return $result;
  });
 }
}
