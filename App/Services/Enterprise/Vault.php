<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
use RuntimeException;
use InvalidArgumentException;
final class Vault
{
 public function __construct(private array $keys,private string $active){
  if(!isset($keys[$active])||!function_exists('openssl_encrypt'))throw new RuntimeException('Bóveda no configurada');
  foreach($keys as $id=>$key)if(!preg_match('/^[A-Za-z0-9_-]{1,40}$/D',(string)$id)||!is_string($key)||strlen($key)!==32)throw new InvalidArgumentException('Clave maestra inválida');
 }
 public function seal(int $tenant,string $connection,string $secret): string{
  if($tenant<1||$secret===''||strlen($secret)>16000)throw new InvalidArgumentException('Secreto inválido');$connection=Core::name($connection,80);
  $iv=random_bytes(12);$tag='';$aad=Core::json([1,$this->active,$tenant,$connection]);
  $encrypted=openssl_encrypt($secret,'aes-256-gcm',$this->keys[$this->active],OPENSSL_RAW_DATA,$iv,$tag,$aad,16);
  if($encrypted===false)throw new RuntimeException('No se pudo cifrar');
  return Core::json(['v'=>1,'kid'=>$this->active,'iv'=>base64_encode($iv),'tag'=>base64_encode($tag),'data'=>base64_encode($encrypted)],24000);
 }
 public function open(int $tenant,string $connection,string $envelope): string{
  try{
   if(strlen($envelope)>24000)throw new RuntimeException();$p=json_decode($envelope,true,8,JSON_THROW_ON_ERROR);$key=$this->keys[$p['kid']??'']??null;
   if(($p['v']??null)!==1||!$key)throw new RuntimeException();
   $iv=base64_decode($p['iv']??'',true);$tag=base64_decode($p['tag']??'',true);$data=base64_decode($p['data']??'',true);
   if($iv===false||strlen($iv)!==12||$tag===false||strlen($tag)!==16||$data===false)throw new RuntimeException();
   $value=openssl_decrypt($data,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,Core::json([1,$p['kid'],$tenant,$connection]));
   if($value===false)throw new RuntimeException();return $value;
  }catch(\Throwable $e){throw new RuntimeException('No se pudo abrir la credencial');}
 }
}
