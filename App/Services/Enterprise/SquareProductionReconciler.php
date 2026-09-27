<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
use RuntimeException;
use Closure;
final class SquareProductionReconciler {
 public function __construct(private Closure $read){}
 private function get(string $type,string $id,string $field):array {
  if(!preg_match('/^[A-Za-z0-9_.:-]{1,192}$/D',$id))throw new RuntimeException('invalid_reference');
  $r=($this->read)('GET','/v2/'.$type.'/'.$id,null)[$field]??[];
  if(($r['id']??'')!==$id)throw new RuntimeException('identity_mismatch');return $r;
 }
 public function run(array $state,?array $binding=null):array {
  $c=$state['context']??[];
  if(($c['environment']??'')!=='production'||!is_int($c['tenant_id']??null)||$c['tenant_id']<1||!is_int($c['cents']??null)||$c['cents']<100||($c['currency']??'')!=='USD')throw new RuntimeException('context_invalid');
  $link=SquareProductionContract::link($state['steps']['link']['result']??[]);
  $variation=$state['steps']['variation']['result']['id']??'';
  $valid=false;foreach(($this->read)('GET','/v2/locations',null)['locations']??[]as$l)
   if(SquareProductionReader::validLocation($l,$c['location_id']??'')&&($l['merchant_id']??'')===($c['merchant_id']??null))$valid=true;
  if(!$valid)throw new RuntimeException('location_mismatch');
  $v=$this->get('catalog/object',$variation,'object');
  SquareProductionContract::payload($v,$variation,$c['location_id'],$c['cents'],str_repeat('a',40));
  if(($v['subscription_plan_variation_data']['subscription_plan_id']??'')!==($state['steps']['plan']['result']['id']??null))throw new RuntimeException('variation_mismatch');
  $order=$this->get('orders',$link['order_id'],'order');SquareProductionContract::order($order,$link['order_id'],$c['location_id'],$c['cents']);
  $out=['empresa_id'=>$c['tenant_id'],'estado_pedido'=>$order['state']??'UNKNOWN','pago_verificado'=>false,'suscripcion_correlacionada'=>false,'factura_actual_pagada'=>false,'activa_empresa'=>false];
  $tenders=$order['tenders']??[];
  if(!$tenders)return $out+['resultado'=>($order['state']??'')==='CANCELED'?'pedido_cancelado':'pendiente_de_pago'];
  if(count($tenders)!==1)throw new RuntimeException('ambiguous_payments');
  $payment=$this->get('payments',$tenders[0]['payment_id']??$tenders[0]['id']??'','payment');
  if(($payment['order_id']??'')!==$link['order_id']||($payment['location_id']??'')!==$c['location_id']||($payment['amount_money']??null)!==['amount'=>$c['cents'],'currency'=>'USD']){
   // JSON object key order does not carry meaning.
   if(($payment['order_id']??'')!==$link['order_id']||($payment['location_id']??'')!==$c['location_id']||($payment['amount_money']['amount']??null)!==$c['cents']||($payment['amount_money']['currency']??'')!=='USD')throw new RuntimeException('payment_mismatch');
  }
  if((int)($payment['refunded_money']['amount']??0)>0)return $out+['resultado'=>'reembolso_requiere_revision'];
  if(($payment['status']??'')!=='COMPLETED')return $out+['resultado'=>'pago_no_completado'];
  $out['pago_verificado']=true;
  $customer=$payment['customer_id']??$order['customer_id']??'';
  if(!empty($payment['customer_id'])&&!empty($order['customer_id'])&&$payment['customer_id']!==$order['customer_id'])throw new RuntimeException('customer_mismatch');
  if(!is_string($customer)||$customer==='')return $out+['resultado'=>'cliente_pendiente_de_identificar'];
  $matches=[];$cursor=null;$pages=0;
  do{
   $body=['limit'=>100,'query'=>['filter'=>['location_ids'=>[$c['location_id']],'customer_ids'=>[$customer]]]];if($cursor)$body['cursor']=$cursor;
   $r=($this->read)('POST','/v2/subscriptions/search',$body);
   foreach($r['subscriptions']??[]as$s)if(($s['customer_id']??'')===$customer&&($s['location_id']??'')===$c['location_id']&&($s['plan_variation_id']??'')===$variation)$matches[$s['id']]=$s;
   $cursor=$r['cursor']??null;$pages++;
  }while($cursor&&$pages<3);
  if($cursor)throw new RuntimeException('search_incomplete');
  if(count($matches)===0)return $out+['resultado'=>'suscripcion_pendiente'];
  if(count($matches)!==1)throw new RuntimeException('ambiguous_subscriptions');
  $sub=$this->get('subscriptions',array_key_first($matches),'subscription');
  if(($sub['customer_id']??'')!==$customer||($sub['location_id']??'')!==$c['location_id']||($sub['plan_variation_id']??'')!==$variation)throw new RuntimeException('subscription_mismatch');
  $ids=$sub['invoice_ids']??[];if(!$ids)return $out+['resultado'=>'factura_pendiente'];
  $correlated=false;$latest=null;$origin=null;
  $scan=array_slice($ids,0,12);
  if($binding && ($binding['subscription_id']??'')===$sub['id'] && !empty($binding['origin_invoice_id']) && !in_array($binding['origin_invoice_id'],$scan,true))$scan[]=$binding['origin_invoice_id'];
  foreach($scan as$index=>$id){
   $inv=$this->get('invoices',$id,'invoice');
   if(($inv['subscription_id']??'')!==$sub['id']||($inv['location_id']??'')!==$c['location_id']||($inv['primary_recipient']['customer_id']??'')!==$customer)throw new RuntimeException('invoice_mismatch');
   if($index===0)$latest=$inv;
   if(($inv['order_id']??'')===$link['order_id']&&($inv['status']??'')==='PAID'){$correlated=true;$origin=$id;}
  }
  if(!$correlated)return $out+['resultado'=>'correlacion_de_factura_pendiente'];
  $out['suscripcion_correlacionada']=true;$out['estado_suscripcion']=$sub['status']??'UNKNOWN';$out['estado_factura']=$latest['status']??'UNKNOWN';
  if(in_array($latest['status']??'',['REFUNDED','PARTIALLY_REFUNDED'],true))return $out+['resultado'=>'reembolso_requiere_revision'];
  if(($latest['status']??'')!=='PAID')return $out+['resultado'=>'factura_actual_no_pagada'];
  $invoiceOrder=$this->get('orders',$latest['order_id']??'','order');SquareProductionContract::order($invoiceOrder,$latest['order_id'],$c['location_id'],$c['cents']);
  $t=$invoiceOrder['tenders']??[];
  if(count($t)!==1)throw new RuntimeException('invoice_payment_unverified');
  $paid=$this->get('payments',$t[0]['payment_id']??$t[0]['id']??'','payment');
  if(($paid['order_id']??'')!==$latest['order_id']||($paid['location_id']??'')!==$c['location_id']||($paid['status']??'')!=='COMPLETED'||($paid['amount_money']['amount']??null)!==$c['cents']||($paid['amount_money']['currency']??'')!=='USD')throw new RuntimeException('invoice_payment_unverified');
  if((int)($paid['refunded_money']['amount']??0)>0)return $out+['resultado'=>'reembolso_requiere_revision'];
  $out['factura_actual_pagada']=true;
  $current=$this->get('subscriptions',$sub['id'],'subscription');
  foreach(['version','charged_through_date','invoice_ids','status','customer_id','location_id','plan_variation_id']as$key)if(($current[$key]??null)!==($sub[$key]??null))throw new RuntimeException('provider_changed_during_read');
  $out['proof']=['subscription_id'=>$sub['id'],'invoice_id'=>$latest['id'],'origin_invoice_id'=>$origin,'charged_through_date'=>$sub['charged_through_date']??null,'timezone'=>$sub['timezone']??null];
  return $out+['resultado'=>($sub['status']??'')==='ACTIVE'?'suscripcion_y_factura_correlacionadas':'suscripcion_no_activa'];
 }
}
