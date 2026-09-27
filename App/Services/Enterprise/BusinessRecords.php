<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
use PDO;
use RuntimeException;
use InvalidArgumentException;
/** Human-operated internal records. Never sends, charges or books an external provider. */
final class BusinessRecords
{
 public const KINDS=['customer'=>['active','archived'],'opportunity'=>['new','contacted','proposal','won','lost'],'appointment'=>['scheduled','confirmed','completed','canceled'],'finance'=>['draft','recorded','void'],'shipment'=>['planned','in_transit','delivered','canceled'],'directive'=>['open','in_progress','completed','canceled'],'hr_task'=>['open','in_progress','completed','canceled'],'campaign'=>['draft','reviewed','archived'],'document'=>['draft','reviewed','archived'],'inventory'=>['active','archived'],'order'=>['draft','confirmed','fulfilled','canceled']];
 public function __construct(private Core $core){}
 private function access(int $tenant,int $actor,string $kind,bool $write=false):string{
  if(!isset(self::KINDS[$kind]))throw new InvalidArgumentException('Tipo inválido');
  return $this->core->authorize($tenant,$actor,in_array($kind,['finance','hr_task'],true)?'configure':($write?'execute':'read'));
 }
 public function listing(int $tenant,int $actor,string $kind):array{
  $role=$this->access($tenant,$actor,$kind);
  $rows=$this->core->query('SELECT id,kind,title,status,data_json,version,created_at,updated_at FROM ai_business_records WHERE tenant_id=? AND kind=? ORDER BY updated_at DESC,id DESC LIMIT 100',[$tenant,$kind])->fetchAll(PDO::FETCH_ASSOC);
  foreach($rows as &$r){$r['fields']=json_decode($r['data_json'],true,16,JSON_THROW_ON_ERROR);unset($r['data_json']);$r['version']=(int)$r['version'];}unset($r);
  return ['records'=>$rows,'can_edit'=>in_array($role,['owner','admin','operator'],true),'limit'=>100];
 }
 private function normalize(string $kind,array $fields):array{
  $allowed=match($kind){'customer'=>['contact','notes'],'opportunity'=>['contact','amount_cents','currency','notes'],'appointment'=>['contact','resource','starts_at','ends_at','notes'],'finance'=>['direction','amount_cents','currency','reference','notes'],'shipment'=>['contact','tracking','notes'],'directive'=>['responsible','notes'],'hr_task'=>['contact','category','notes'],'campaign'=>['audience','channel','notes'],'document'=>['reference','notes'],'inventory'=>['reference','quantity','notes'],'order'=>['contact','reference','notes']};
  if(array_diff(array_keys($fields),$allowed))throw new InvalidArgumentException('Campo no admitido');
  $result=[];
  foreach($allowed as $key){
   $v=$fields[$key]??null;
   if(in_array($key,['amount_cents','starts_at','ends_at','quantity'],true)){
    if(!is_int($v)||$v<0||$v>($key==='quantity'?1000000000:($key==='amount_cents'?100000000000:4102444800)))throw new InvalidArgumentException('Importe o fecha inválidos');
   }else{
    if(!is_string($v)||strlen($v)>($key==='notes'?4000:240)||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$v))throw new InvalidArgumentException('Texto inválido');$v=trim($v);
    if($key==='quantity'&&$v>1000000000)throw new InvalidArgumentException('Cantidad inválida');
    if($key==='category'&&!in_array($v,['onboarding','training','administrative'],true))throw new InvalidArgumentException('Solo tareas administrativas de personal');
    if($key==='currency'&&!in_array($v,['USD','MXN','COP','CRC','NIO','GTQ','HNL','PAB','DOP','ARS','BRL','CLP','PEN','UYU','PYG','BOB','VES'],true))throw new InvalidArgumentException('Moneda inválida');
    if($key==='direction'&&!in_array($v,['income','expense'],true))throw new InvalidArgumentException('Movimiento inválido');
    if($key==='resource'&&$v==='')throw new InvalidArgumentException('Falta recurso de reserva');
   }
   $result[$key]=$v;
  }
  if($kind==='appointment'&&($result['starts_at']<1||$result['ends_at']<=$result['starts_at']||$result['ends_at']-$result['starts_at']>2678400))throw new InvalidArgumentException('Horario inválido');
  return $result;
 }
 public function validateRecord(string $kind,string $title,string $status,array $fields):void{
  if(!isset(self::KINDS[$kind])||!in_array($status,self::KINDS[$kind],true))throw new InvalidArgumentException('Tipo o estado inválidos');
  Core::name($title,180);$this->normalize($kind,$fields);
 }
 public function save(int $tenant,int $actor,string $id,string $kind,string $title,string $status,array $fields,int $version,int $now):array{
  $this->access($tenant,$actor,$kind,true);
  if(!preg_match('/^[a-f0-9]{32}$/D',$id)||$version<0||!in_array($status,self::KINDS[$kind],true))throw new InvalidArgumentException('Registro inválido');
  $title=Core::name($title,180);$fields=$this->normalize($kind,$fields);$json=Core::json($fields,8000);
  $hash=hash('sha256',Core::json([$kind,$title,$status,$fields],10000));
  return $this->core->transaction(function()use($tenant,$actor,$id,$kind,$title,$status,$fields,$version,$now,$json,$hash){
   $c=$this->core;$c->lock($tenant);$this->access($tenant,$actor,$kind,true);
   $row=$c->query('SELECT * FROM ai_business_records WHERE tenant_id=? AND id=?',[$tenant,$id])->fetch(PDO::FETCH_ASSOC);
   if($row){
    if($row['kind']!==$kind)throw new RuntimeException('Tipo diferente',409);
    if($version===0&&(int)$row['created_by']===$actor&&hash_equals($row['create_hash'],$hash))return ['id'=>$id,'version'=>(int)$row['version'],'duplicate'=>true];
    if((int)$row['version']!==$version)throw new RuntimeException('Actualiza el registro antes de guardar',409);
    if($kind==='finance'&&$row['status']!=='draft'){
     if($status!=='void'||$row['status']==='void'||$row['data_json']!==$json||$row['title']!==$title)throw new RuntimeException('Un movimiento registrado solo puede anularse conservando sus datos',409);
    }
   }elseif($version!==0)throw new RuntimeException('Registro no disponible',404);
   // Tenant lock serializes reservation writes, including concurrent requests.
   if($kind==='appointment'&&in_array($status,['scheduled','confirmed'],true)){
    $conflict=$c->query("SELECT id FROM ai_business_records WHERE tenant_id=? AND kind='appointment' AND id<>? AND status IN ('scheduled','confirmed') AND resource_key=? AND starts_at<? AND ends_at>? LIMIT 1",[$tenant,$id,mb_strtolower($fields['resource'],'UTF-8'),$fields['ends_at'],$fields['starts_at']])->fetchColumn();
    if($conflict)throw new RuntimeException('El recurso ya tiene una reserva en ese horario',409);
   }
   $resource=$kind==='appointment'?mb_strtolower($fields['resource'],'UTF-8'):null;$start=$fields['starts_at']??null;$end=$fields['ends_at']??null;
   if($row)$c->query('UPDATE ai_business_records SET title=?,status=?,data_json=?,resource_key=?,starts_at=?,ends_at=?,version=version+1,updated_at=?,updated_by=? WHERE tenant_id=? AND id=?',[$title,$status,$json,$resource,$start,$end,$now,$actor,$tenant,$id]);
   else $c->query('INSERT INTO ai_business_records(tenant_id,id,kind,title,status,data_json,resource_key,starts_at,ends_at,version,create_hash,created_by,updated_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,1,?,?,?,?,?)',[$tenant,$id,$kind,$title,$status,$json,$resource,$start,$end,$hash,$actor,$actor,$now,$now]);
   $c->audit($tenant,$actor,$row?'business.updated':'business.created',$id,['status'=>$status,'version'=>$version+1],$now);
   return ['id'=>$id,'version'=>$version+1,'duplicate'=>false];
  });
 }
}
