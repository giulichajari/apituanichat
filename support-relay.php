<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');
require __DIR__.'/vendor/autoload.php';
\Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
try{
 $db=\App\Configs\Database::getInstance()->getConnection();
 $relay=new \App\Services\Enterprise\SupportRelay($db,(int)($_ENV['BOT_USER_ID']??0),\Closure::fromCallable([\App\Services\Enterprise\SupportRelay::class,'post']));
 $relay->collect(time());$result=$relay->tick(time());
 echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){fwrite(STDERR,"support_relay_check_required\n");exit(1);}
