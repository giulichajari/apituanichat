<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');
require __DIR__.'/vendor/autoload.php';
\Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
try{
 $db=\App\Configs\Database::getInstance()->getConnection();
 $runtime=\App\Services\Enterprise\SupportRuntime::delivery($db);
 $runtime->flush(\Closure::fromCallable([\App\Services\Enterprise\SupportRuntime::class,'broadcast']),100);
}catch(Throwable $e){fwrite(STDERR,"support_notice_retry_pending\n");exit(1);}
