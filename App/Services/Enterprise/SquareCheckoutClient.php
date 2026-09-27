<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
final class SquareCheckoutClient {
 public function request(string $method,string $path,?array $payload=null):array {
  $read=$method==='GET'&&preg_match('~^/v2/(?:locations|catalog/object/[A-Za-z0-9%_.:-]+|online-checkout/payment-links/[A-Za-z0-9_-]+|orders/[A-Za-z0-9_-]+|payments/[A-Za-z0-9_-]+)$~D',$path);
  if(!$read&&!($method==='POST'&&$path==='/v2/online-checkout/payment-links'))throw new \RuntimeException('invalid_path');
  $token=trim((string)($_ENV['SQUARE_ACCESS_TOKEN_SANDBOX']??''));
  if($token===''||preg_match('/[\r\n]/',$token))throw new \RuntimeException('sandbox_not_configured');
  $body='';$ch=curl_init('https://connect.squareupsandbox.com'.$path);
  $options=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,
   CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
   CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Square-Version: 2025-03-19','Content-Type: application/json'],
   CURLOPT_WRITEFUNCTION=>static function($c,string $part)use(&$body):int{if(strlen($body)+strlen($part)>2097152)return 0;$body.=$part;return strlen($part);}];
  if($payload!==null)$options[CURLOPT_POSTFIELDS]=json_encode($payload,JSON_THROW_ON_ERROR);
  curl_setopt_array($ch,$options);curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_errno($ch);curl_close($ch);
  if($error)throw new \RuntimeException('provider_network');
  $data=json_decode($body,true);if($http<200||$http>=300||!is_array($data)||!empty($data['errors']))throw new \RuntimeException('provider_unavailable');
  return $data;
 }
}
