<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
ini_set('display_errors','0');ini_set('log_errors','0');
try{
 require __DIR__.'/vendor/autoload.php';\Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
 $lock=fopen('/run/tuanichat-square-sandbox/sync.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit(0);
 $core=new \App\Services\Enterprise\Core(\App\Configs\Database::getInstance()->getConnection());
 $reader=new \App\Services\Enterprise\SquareSandboxReader();
 $service=new \App\Services\Enterprise\SquareCompanySandbox($core,fn($path)=>$reader->get($path));
 $tenant=$core->query('SELECT tenant_id FROM ai_square_company_sandbox ORDER BY checked_at LIMIT 1')->fetchColumn();
 if(!$tenant){echo "Sin empresa de prueba vinculada.\n";exit(0);}
 $result=$service->sync((int)$tenant,time());
 echo json_encode(['environment'=>'sandbox','status'=>$result['status'],'real_access'=>false]).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,"No se pudo verificar Square Sandbox. Consultar estado del panel.\n");exit(1);}
