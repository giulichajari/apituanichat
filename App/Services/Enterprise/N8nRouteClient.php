<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
use RuntimeException;
final class N8nRouteClient
{
 public const URL='https://n8n.tuanichat.com/webhook/tuanichat-enterprise-route-v1';
 public function __construct(private string $url,private string $token){
  if($url!==self::URL||!preg_match('/^[a-f0-9]{64}$/D',$token))throw new RuntimeException('Configuración inválida');
 }
 public function send(array $request):array{
  $body=json_encode($request,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
  if(strlen($body)>8000)throw new RuntimeException('Solicitud demasiado grande');
  $response='';$ch=curl_init($this->url);
  curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json','X-Tuani-Orchestrator-Token: '.$this->token],CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_PROXY=>'',CURLOPT_WRITEFUNCTION=>static function($handle,$chunk)use(&$response){if(strlen($response)+strlen($chunk)>8192)return 0;$response.=$chunk;return strlen($chunk);}]);
  try{
   $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
   if($ok===false||$status!==200)throw new RuntimeException('n8n no confirmó la solicitud');
   $parsed=json_decode($response,true,32,JSON_THROW_ON_ERROR);
   if(!is_array($parsed)||array_is_list($parsed))throw new RuntimeException('Respuesta inválida');
   return $parsed;
  }finally{curl_close($ch);}
 }
}
