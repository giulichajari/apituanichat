<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
use PDO;
use RuntimeException;
final class SquareProductionStatus {
 public function __construct(private Core $core){}
 public function status(int $tenant,int $actor,int $now):array {
  $this->core->authorize($tenant,$actor,'configure');
  $r=$this->core->query('SELECT price_cents,result_json,checked_at FROM ai_square_production_status WHERE tenant_id=?',[$tenant])->fetch(PDO::FETCH_ASSOC);
  if(!$r)return ['environment'=>'production','prepared'=>false,'activates_companies'=>false];
  $access=$this->core->query('SELECT status,valid_until FROM ai_subscriptions WHERE tenant_id=?',[$tenant])->fetch(PDO::FETCH_ASSOC);
  $data=json_decode($r['result_json']??'null',true);
  return ['environment'=>'production','prepared'=>true,'price_cents'=>(int)$r['price_cents'],'snapshot'=>self::safe(is_array($data)?$data:[]),'checked_at'=>(int)$r['checked_at'],'stale'=>(int)$r['checked_at']===0||$now-(int)$r['checked_at']>300,'access'=>['status'=>$access['status']??'paused','valid_until'=>(int)($access['valid_until']??0),'active'=>($access['status']??'')==='active'&&(int)($access['valid_until']??0)>$now],'activation_after_verified_payment'=>true];
 }
 public static function safe(array $data):array {
  return array_intersect_key($data,array_flip(['resultado','estado_pedido','pago_verificado','suscripcion_correlacionada','factura_actual_pagada','estado_suscripcion','estado_factura']));
 }
 public function sync(int $tenant,\Closure $read,int $now):array {
  $r=$this->core->query('SELECT state_json FROM ai_square_production_status WHERE tenant_id=?',[$tenant])->fetch(PDO::FETCH_ASSOC);
  if(!$r)throw new RuntimeException('not_prepared');
  try{
   $state=json_decode($r['state_json'],true,512,JSON_THROW_ON_ERROR);$c=$state['context']??[];
   if(($c['tenant_id']??null)!==$tenant)throw new RuntimeException('identity_mismatch');
   $owner=$this->core->query("SELECT t.created_by FROM ai_tenants t JOIN ai_members m ON m.tenant_id=t.id AND m.user_id=t.created_by AND m.role='owner' AND m.status='active' WHERE t.id=?",[$tenant])->fetchColumn();
   if($owner===false||(int)$owner!==($c['owner_id']??null))throw new RuntimeException('owner_changed');
   $binding=$this->core->query('SELECT subscription_id,origin_invoice_id FROM ai_square_access WHERE tenant_id=?',[$tenant])->fetch(PDO::FETCH_ASSOC);
   $result=(new SquareProductionReconciler($read))->run($state,$binding?:null);
  }catch(\Throwable $e){$result=['resultado'=>'requiere_revision'];}
  $commit=function(array $value)use($tenant,$r,$now){
   return $this->core->transaction(function()use($tenant,$r,$now,$value){
    $this->core->lock($tenant);
    $fresh=$this->core->query('SELECT state_json,checked_at FROM ai_square_production_status WHERE tenant_id=?'.$this->core->lockSuffix(),[$tenant])->fetch(PDO::FETCH_ASSOC);
    if(!$fresh||$fresh['state_json']!==$r['state_json']||(int)$fresh['checked_at']>$now)return;
    if(isset($value['proof'])){
     $ctx=json_decode($fresh['state_json'],true,512,JSON_THROW_ON_ERROR)['context'];
     $owner=$this->core->query("SELECT t.created_by FROM ai_tenants t JOIN ai_members m ON m.tenant_id=t.id AND m.user_id=t.created_by AND m.role='owner' AND m.status='active' WHERE t.id=?",[$tenant])->fetchColumn();
     if($owner===false||(int)$owner!==$ctx['owner_id'])throw new RuntimeException('owner_changed');
    }
    (new SquareAccess($this->core))->apply($tenant,$value,$now);
    $this->core->query('UPDATE ai_square_production_status SET result_json=?,checked_at=? WHERE tenant_id=?',[json_encode(self::safe($value),JSON_THROW_ON_ERROR),$now,$tenant]);
   });
  };
  try{$commit($result);}catch(\Throwable $e){$result=['resultado'=>'requiere_revision'];$commit($result);}
  return self::safe($result);
 }
}
