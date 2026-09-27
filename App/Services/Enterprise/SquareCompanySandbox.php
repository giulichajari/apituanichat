<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
use PDO;
use RuntimeException;
final class SquareCompanySandbox{
 public const CANDIDATE='/etc/tuanichat/square-sandbox-candidate.json';
 public function __construct(private Core $core,private \Closure $read){}
 public static function candidate():array{
  if(!is_readable(self::CANDIDATE))throw new RuntimeException('candidate_missing',503);
  $r=json_decode((string)file_get_contents(self::CANDIDATE),true);
  if(!is_array($r)||($r['environment']??'')!=='sandbox')throw new RuntimeException('candidate_missing',503);
  foreach(['subscription_id','customer_id','variation_id','location_id','merchant_id'] as $key)if(!is_string($r[$key]??null)||$r[$key]==='')throw new RuntimeException('candidate_missing',503);
  return $r;
 }
 private function authorize(int $tenant,int $actor,bool $owner=false):void{
  $role=$this->core->authorize($tenant,$actor);
  if(strtoupper((string)$this->core->query('SELECT rol FROM users WHERE id=?',[$actor])->fetchColumn())!=='ADMIN'||($owner&&$role!=='owner'))throw new RuntimeException('forbidden',403);
 }
 public function link(int $tenant,int $actor,array $candidate,int $now):void{
  $this->core->transaction(function()use($tenant,$actor,$candidate,$now){
   $this->core->lock($tenant);$this->authorize($tenant,$actor,true);
   if(($candidate['environment']??'')!=='sandbox')throw new RuntimeException('wrong_environment',400);
   foreach(['subscription_id','customer_id','variation_id','location_id','merchant_id'] as $key)if(!is_string($candidate[$key]??null)||$candidate[$key]===''||strlen($candidate[$key])>180)throw new RuntimeException('invalid_candidate',400);
   $existing=$this->core->query('SELECT tenant_id,subscription_id FROM ai_square_company_sandbox WHERE tenant_id=? OR subscription_id=?',[$tenant,$candidate['subscription_id']])->fetch(PDO::FETCH_ASSOC);
   if($existing){if((int)$existing['tenant_id']===$tenant&&$existing['subscription_id']===$candidate['subscription_id'])return;throw new RuntimeException('already_linked',409);}
   try{$this->core->query('INSERT INTO ai_square_company_sandbox(tenant_id,subscription_id,customer_id,variation_id,location_id,merchant_id,status,checked_at,error_code,linked_by,linked_at) VALUES(?,?,?,?,?,?,?,0,NULL,?,?)',[$tenant,$candidate['subscription_id'],$candidate['customer_id'],$candidate['variation_id'],$candidate['location_id'],$candidate['merchant_id'],'awaiting_sync',$actor,$now]);}
   catch(\PDOException $e){if((string)$e->getCode()==='23000')throw new RuntimeException('already_linked',409);throw $e;}
   $this->core->audit($tenant,$actor,'square.sandbox_linked','sandbox',['status'=>'awaiting_sync'],$now);
  });
 }
 public function status(int $tenant,int $actor):array{
  $this->authorize($tenant,$actor);
  $row=$this->core->query('SELECT status,square_status,invoice_status,checked_at,error_code,linked_at FROM ai_square_company_sandbox WHERE tenant_id=?',[$tenant])->fetch(PDO::FETCH_ASSOC);
  return ['environment'=>'sandbox','linked'=>(bool)$row,'snapshot'=>$row?:null,'real_access'=>false];
 }
 public static function evaluate(array $sub,?array $invoice,array $expected):array{
  foreach(['id'=>'subscription_id','customer_id'=>'customer_id','plan_variation_id'=>'variation_id','location_id'=>'location_id'] as $field=>$key)if(($sub[$field]??null)!==$expected[$key])throw new RuntimeException('identity_mismatch');
  $state=$sub['status']??'';
  if(!in_array($state,['ACTIVE','PENDING','CANCELED','DEACTIVATED','PAUSED','COMPLETED'],true))throw new RuntimeException('unknown_provider_status');
  $invoiceStatus=null;
  if($invoice!==null){
   if(($invoice['subscription_id']??'')!==$expected['subscription_id']||($invoice['location_id']??'')!==$expected['location_id']||($invoice['primary_recipient']['customer_id']??'')!==$expected['customer_id'])throw new RuntimeException('invoice_mismatch');
   $invoiceStatus=$invoice['status']??'';
   if(!in_array($invoiceStatus,['DRAFT','UNPAID','SCHEDULED','PARTIALLY_PAID','PAID','PARTIALLY_REFUNDED','REFUNDED','CANCELED','FAILED','PAYMENT_PENDING'],true))throw new RuntimeException('unknown_invoice_status');
  }
  $status=match($state){'ACTIVE'=>match($invoiceStatus){'PAID'=>'paid','REFUNDED','PARTIALLY_REFUNDED'=>'review_refund',default=>'payment_pending'},'CANCELED','COMPLETED'=>'ended','PENDING'=>'pending',default=>'inactive'};
  return ['status'=>$status,'square_status'=>$state,'invoice_status'=>$invoiceStatus];
 }
 public function sync(int $tenant,int $now):array{
  $r=$this->core->query('SELECT * FROM ai_square_company_sandbox WHERE tenant_id=?',[$tenant])->fetch(PDO::FETCH_ASSOC);if(!$r)throw new RuntimeException('not_linked');
  try{
   $locations=($this->read)('/v2/locations');$valid=false;
   foreach($locations['locations']??[] as $location)if(($location['id']??'')===$r['location_id']&&($location['merchant_id']??'')===$r['merchant_id']&&($location['currency']??'')==='USD'&&($location['country']??'')==='US'&&($location['status']??'')==='ACTIVE')$valid=true;
   if(!$valid)throw new RuntimeException('location_mismatch');
   $sub=($this->read)('/v2/subscriptions/'.rawurlencode($r['subscription_id']))['subscription']??[];
   // The API returns invoice IDs newest first. Never fall back to an older paid invoice.
   $invoice=null;$invoiceId=$sub['invoice_ids'][0]??null;
   if($invoiceId!==null){if(!is_string($invoiceId)||$invoiceId==='')throw new RuntimeException('invoice_mismatch');
    $invoice=($this->read)('/v2/invoices/'.rawurlencode($invoiceId))['invoice']??[];
    if(($invoice['id']??'')!==$invoiceId)throw new RuntimeException('invoice_mismatch');
   }
   $value=self::evaluate($sub,$invoice,$r);
   $this->core->query('UPDATE ai_square_company_sandbox SET status=?,square_status=?,invoice_status=?,checked_at=?,error_code=NULL WHERE tenant_id=? AND checked_at<=?',[$value['status'],$value['square_status'],$value['invoice_status'],$now,$tenant,$now]);
   return $value;
  }catch(\Throwable $e){
   $code=in_array($e->getMessage(),['provider_network','provider_unavailable','sandbox_not_configured','location_mismatch','identity_mismatch','invoice_mismatch','unknown_provider_status','unknown_invoice_status'],true)?$e->getMessage():'sync_failed';
   $this->core->query("UPDATE ai_square_company_sandbox SET status='verification_pending',error_code=?,checked_at=? WHERE tenant_id=? AND checked_at<=?",[$code,$now,$tenant,$now]);
   throw new RuntimeException($code,503);
  }
 }
}
