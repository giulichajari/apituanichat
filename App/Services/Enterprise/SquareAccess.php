<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
use PDO;
use RuntimeException;
final class SquareAccess {
 public function __construct(private Core $core){}
 public static function until(array $proof,int $now):int {
  foreach(['subscription_id','invoice_id','origin_invoice_id']as$key)if(!is_string($proof[$key]??null)||!preg_match('/^[A-Za-z0-9_.:-]{1,180}$/D',$proof[$key]))throw new RuntimeException('invalid_proof');
  $date=$proof['charged_through_date']??'';$zone=$proof['timezone']??'';
  if(!is_string($date)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)||!is_string($zone)||!in_array($zone,\DateTimeZone::listIdentifiers(),true))throw new RuntimeException('period_unverified');
  $end=\DateTimeImmutable::createFromFormat('!Y-m-d',$date,new \DateTimeZone($zone));
  if(!$end||$end->format('Y-m-d')!==$date)throw new RuntimeException('period_unverified');
  $until=$end->modify('+1 day')->getTimestamp();
  if($until<=$now||$until>$now+40*86400)throw new RuntimeException('period_unverified');
  return $until;
 }
 // Called inside the tenant transaction after reading live Square data; never from HTTP payloads.
 public function apply(int $tenant,array $result,int $now):void {
  $this->core->lock($tenant);
  $row=$this->core->query('SELECT * FROM ai_subscriptions WHERE tenant_id=?'.$this->core->lockSuffix(),[$tenant])->fetch(PDO::FETCH_ASSOC);
  if(!$row||(!empty($row['provider'])&&$row['provider']!=='square_production'))throw new RuntimeException('subscription_managed_elsewhere');
  if(empty($row['provider'])&&($row['status']!=='paused'||(int)$row['valid_until']!==0))throw new RuntimeException('existing_entitlement_requires_review');
  $ledger=$this->core->query('SELECT * FROM ai_square_access WHERE tenant_id=?'.$this->core->lockSuffix(),[$tenant])->fetch(PDO::FETCH_ASSOC);
  $status=$row['status'];$until=(int)$row['valid_until'];$sub=$row['provider_subscription_id'];
  if(($result['resultado']??'')==='reembolso_requiere_revision'){
   if($ledger)$this->core->query('UPDATE ai_square_access SET held=1 WHERE tenant_id=?',[$tenant]);
   if($row['provider']==='square_production'){$status='paused';$until=0;}
  }elseif(($result['resultado']??'')==='suscripcion_y_factura_correlacionadas'&&($result['pago_verificado']??false)===true&&($result['suscripcion_correlacionada']??false)===true&&($result['factura_actual_pagada']??false)===true&&empty($ledger['held'])){
   $proof=$result['proof']??[];$candidate=self::until($proof,$now);
   if($ledger&&$ledger['subscription_id']!==$proof['subscription_id'])throw new RuntimeException('subscription_changed');
   if($ledger&&$ledger['invoice_id']===$proof['invoice_id']&&(int)$ledger['valid_until']!==$candidate)throw new RuntimeException('invoice_period_changed');
   if(!$ledger){
    $this->core->query('INSERT INTO ai_square_access(tenant_id,subscription_id,invoice_id,origin_invoice_id,valid_until,held) VALUES(?,?,?,?,?,0)',[$tenant,$proof['subscription_id'],$proof['invoice_id'],$proof['origin_invoice_id'],$candidate]);
   }elseif($candidate>=(int)$ledger['valid_until']){
    $this->core->query('UPDATE ai_square_access SET invoice_id=?,valid_until=? WHERE tenant_id=?',[$proof['invoice_id'],$candidate,$tenant]);
   }else throw new RuntimeException('older_period');
   $status='active';$until=$candidate;$sub=$proof['subscription_id'];
  }elseif($row['provider']==='square_production'&&$status==='active'&&$until<=$now){$status='past_due';}
  if($status!==$row['status']||$until!==(int)$row['valid_until']||$sub!==$row['provider_subscription_id']){
   $this->core->query("UPDATE ai_subscriptions SET status=?,valid_until=?,provider='square_production',provider_subscription_id=?,version=version+1 WHERE tenant_id=?",[$status,$until,$sub,$tenant]);
   $this->core->audit($tenant,0,'square.access_updated','production',['status'=>$status],$now);
  }
 }
}
