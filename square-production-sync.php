<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);ini_set('display_errors','0');ini_set('log_errors','0');
try{
 require __DIR__.'/vendor/autoload.php';\Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
 $core=new \App\Services\Enterprise\Core(\App\Configs\Database::getInstance()->getConnection());
 $tenant=$core->query('SELECT tenant_id FROM ai_square_production_status ORDER BY checked_at,tenant_id LIMIT 1')->fetchColumn();
 if($tenant===false){echo "Sin contrataciones registradas.\n";exit;}
 $reader=new \App\Services\Enterprise\SquareProductionReader(trim((string)($_ENV['SQUARE_ACCESS_TOKEN_PROD']??'')));
 $r=(new \App\Services\Enterprise\SquareProductionStatus($core))->sync((int)$tenant,fn($m,$p,$b)=>$reader->request($m,$p,$b),time());
 echo json_encode(['empresa_id'=>(int)$tenant,'resultado'=>$r['resultado']??'requiere_revision','cobros_ejecutados'=>0]).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,"Consulta de producción pendiente de revisión.\n");exit(1);}
