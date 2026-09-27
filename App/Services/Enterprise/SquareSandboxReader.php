<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
final class SquareSandboxReader{
 public function get(string $path):array{
  if(!preg_match('~^/v2/(?:locations|subscriptions/[A-Za-z0-9%_.:-]+|invoices/[A-Za-z0-9%_.:-]+)$~D',$path))throw new \RuntimeException('invalid_path');
  $token=trim((string)($_ENV['SQUARE_ACCESS_TOKEN_SANDBOX']??''));
  if($token===''||preg_match('/[\r\n]/',$token))throw new \RuntimeException('sandbox_not_configured');
  $body='';$ch=curl_init('https://connect.squareupsandbox.com'.$path);
  curl_setopt_array($ch,[CURLOPT_HTTPGET=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>25,
   CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
   CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Square-Version: 2025-03-19','Accept: application/json'],
   CURLOPT_WRITEFUNCTION=>static function($c,string $part)use(&$body):int{if(strlen($body)+strlen($part)>2097152)return 0;$body.=$part;return strlen($part);}]);
  curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_errno($ch);curl_close($ch);
  if($error)throw new \RuntimeException('provider_network');
  $r=json_decode($body,true);if($http!==200||!is_array($r)||!empty($r['errors']))throw new \RuntimeException('provider_unavailable');
  return $r;
 }
}
