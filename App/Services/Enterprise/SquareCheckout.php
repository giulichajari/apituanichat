<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
use PDO;
use RuntimeException;
final class SquareCheckout {
 public function __construct(private Core $core,private \Closure $request){}
 private function authorize(int $tenant,int $actor):void {
  if($this->core->authorize($tenant,$actor)!=='owner'||strtoupper((string)$this->core->query('SELECT rol FROM users WHERE id=?',[$actor])->fetchColumn())!=='ADMIN')throw new RuntimeException('forbidden',403);
 }
 public function status(int $tenant,int $actor):array {
  $this->authorize($tenant,$actor);
  $r=$this->core->query('SELECT price_cents,status,link_json,checked_at,error_code,created_at FROM ai_square_checkout_sandbox WHERE tenant_id=?',[$tenant])->fetch(PDO::FETCH_ASSOC);
  if($r){$link=$r['link_json']?SquareCheckoutContract::link(json_decode($r['link_json'],true,512,JSON_THROW_ON_ERROR)):null;unset($r['link_json']);$r['url']=$link['url']??null;}
  return ['environment'=>'sandbox','checkout'=>$r?:null,'real_access'=>false,'production_enabled'=>false];
 }
 public static function paymentState(array $order,?array $payment,array $context):string {
  SquareCheckoutContract::order($order,$order['id']??'',$context['location_id'],$context['cents']);
  if(!$payment)return 'awaiting_payment';
  if(($payment['order_id']??'')!==($order['id']??'')||($payment['location_id']??'')!==$context['location_id']||($payment['amount_money']['currency']??'')!=='USD'||($payment['amount_money']['amount']??null)!==$context['cents'])throw new RuntimeException('payment_mismatch');
  if((int)($payment['refunded_money']['amount']??0)>0)return 'refunded';
  return match($payment['status']??''){'COMPLETED'=>'paid_sandbox','APPROVED','PENDING'=>'awaiting_payment','CANCELED','FAILED'=>'failed',default=>throw new RuntimeException('payment_mismatch')};
 }
 public function run(int $tenant,int $actor,string $action,int $now):array {
  if(!in_array($action,['create','refresh'],true))throw new RuntimeException('invalid_action',400);
  $lease=bin2hex(random_bytes(16));
  $r=$this->core->transaction(function()use($tenant,$actor,$action,$now,$lease){
   $this->core->lock($tenant);$this->authorize($tenant,$actor);
   $r=$this->core->query('SELECT * FROM ai_square_checkout_sandbox WHERE tenant_id=?',[$tenant])->fetch(PDO::FETCH_ASSOC);
   if(!$r){
    if($action!=='create')throw new RuntimeException('checkout_missing',404);
    $candidate=$this->core->query('SELECT * FROM ai_square_company_sandbox WHERE tenant_id=?',[$tenant])->fetch(PDO::FETCH_ASSOC);
    if(!$candidate)throw new RuntimeException('trial_not_linked',409);
    $plan=$this->core->query("SELECT price_cents,currency FROM ai_plans WHERE code='business'")->fetch(PDO::FETCH_ASSOC);
    if(!$plan||$plan['currency']!=='USD')throw new RuntimeException('plan_invalid',409);
    $context=['environment'=>'sandbox','tenant_id'=>$tenant,'location_id'=>$candidate['location_id'],'merchant_id'=>$candidate['merchant_id'],'variation_id'=>$candidate['variation_id'],'cents'=>(int)$plan['price_cents']];
    $key=substr(hash('sha256','tuani-checkout-sandbox-v1:'.$tenant.':'.$candidate['merchant_id']),0,40);
    $this->core->query("INSERT INTO ai_square_checkout_sandbox(tenant_id,price_cents,context_json,request_key,status,created_at) VALUES(?,?,?,?,'preparing',?)",[$tenant,$context['cents'],json_encode($context,JSON_THROW_ON_ERROR),$key,$now]);
    $this->core->audit($tenant,$actor,'square.checkout_started','sandbox',['status'=>'preparing'],$now);
    $r=$this->core->query('SELECT * FROM ai_square_checkout_sandbox WHERE tenant_id=?',[$tenant])->fetch(PDO::FETCH_ASSOC);
   }
   if((int)$r['lease_until']>$now)throw new RuntimeException('checkout_busy',409);
   if((int)$r['attempted_at']>$now-30)throw new RuntimeException('checkout_wait',429);
   $this->core->query('UPDATE ai_square_checkout_sandbox SET lease_key=?,lease_until=?,attempted_at=? WHERE tenant_id=?',[$lease,$now+180,$now,$tenant]);
   return $r;
  });
  try{
   $c=json_decode($r['context_json'],true,512,JSON_THROW_ON_ERROR);
   if(($c['environment']??'')!=='sandbox'||(int)$c['tenant_id']!==$tenant)throw new RuntimeException('context_mismatch');
   $valid=false;
   foreach(($this->request)('GET','/v2/locations',null)['locations']??[]as$l)if(($l['id']??'')===$c['location_id']&&($l['merchant_id']??'')===$c['merchant_id']&&($l['status']??'')==='ACTIVE'&&($l['country']??'')==='US'&&($l['currency']??'')==='USD'&&in_array('CREDIT_CARD_PROCESSING',$l['capabilities']??[],true))$valid=true;
   if(!$valid)throw new RuntimeException('location_mismatch');
   $link=$r['link_json']?SquareCheckoutContract::link(json_decode($r['link_json'],true,512,JSON_THROW_ON_ERROR)):null;
   if(!$link){
    $v=($this->request)('GET','/v2/catalog/object/'.rawurlencode($c['variation_id']),null)['object']??[];
    $payload=SquareCheckoutContract::payload($v,$c['variation_id'],$c['location_id'],$c['cents'],$r['request_key']);
    $hash=hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR));
    if($r['request_hash']&&$r['request_hash']!==$hash)throw new RuntimeException('checkout_changed');
    $this->core->query('UPDATE ai_square_checkout_sandbox SET request_hash=? WHERE tenant_id=? AND lease_key=?',[$hash,$tenant,$lease]);
    $link=SquareCheckoutContract::link(($this->request)('POST','/v2/online-checkout/payment-links',$payload)['payment_link']??[]);
    $this->core->query('UPDATE ai_square_checkout_sandbox SET link_json=? WHERE tenant_id=? AND lease_key=?',[json_encode($link,JSON_THROW_ON_ERROR),$tenant,$lease]);
   }
   $fresh=SquareCheckoutContract::link(($this->request)('GET','/v2/online-checkout/payment-links/'.rawurlencode($link['id']),null)['payment_link']??[]);
   if($fresh!==$link)throw new RuntimeException('link_changed');
   $order=($this->request)('GET','/v2/orders/'.rawurlencode($link['order_id']),null)['order']??[];
   SquareCheckoutContract::order($order,$link['order_id'],$c['location_id'],$c['cents']);
   $tenders=$order['tenders']??[];if(count($tenders)>1)throw new RuntimeException('multiple_payments');
   $payment=null;
   if(count($tenders)===1){
    $pid=$tenders[0]['payment_id']??$tenders[0]['id']??'';
    if(!is_string($pid)||!preg_match('/^[A-Za-z0-9_-]{1,192}$/D',$pid))throw new RuntimeException('payment_mismatch');
    $payment=($this->request)('GET','/v2/payments/'.rawurlencode($pid),null)['payment']??[];
    if(($payment['id']??'')!==$pid)throw new RuntimeException('payment_mismatch');
   }
   $status=self::paymentState($order,$payment,$c);
   $this->core->transaction(function()use($tenant,$actor,$lease,$status,$now,$r){
    $this->core->lock($tenant);$this->authorize($tenant,$actor);
    $changed=$this->core->query('UPDATE ai_square_checkout_sandbox SET status=?,checked_at=?,error_code=NULL WHERE tenant_id=? AND lease_key=?',[$status,$now,$tenant,$lease])->rowCount();
    if($changed&&$status!==$r['status'])$this->core->audit($tenant,$actor,'square.checkout_checked','sandbox',['status'=>$status],$now);
   });
  }catch(\Throwable $e){
   $code=in_array($e->getMessage(),['provider_network','provider_unavailable','sandbox_not_configured','price_or_cadence_mismatch','variation_mismatch','checkout_changed','location_mismatch','link_changed','order_mismatch','payment_mismatch','multiple_payments'],true)?$e->getMessage():'verification_failed';
   $this->core->query("UPDATE ai_square_checkout_sandbox SET status='verification_pending',error_code=? WHERE tenant_id=? AND lease_key=?",[$code,$tenant,$lease]);
   throw new RuntimeException($code,503);
  }finally{$this->core->query('UPDATE ai_square_checkout_sandbox SET lease_until=0,lease_key=NULL WHERE tenant_id=? AND lease_key=?',[$tenant,$lease]);}
  return $this->status($tenant,$actor);
 }
}
