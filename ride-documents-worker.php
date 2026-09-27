<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
if(($_ENV['RIDE_DOCUMENTS_ENABLED']??getenv('RIDE_DOCUMENTS_ENABLED'))!=='true'){
    fwrite(STDERR,"Trabajador de documentos Ride deshabilitado.\n");exit(2);
}
$db=App\Configs\Database::getInstance()->getConnection();
$worker=new App\Services\RideDocuments($db);
$once=in_array('--once',$argv,true);
do {
    $failed=false;
    try {
        $ids=$db->query("SELECT ride_id FROM ride_documents WHERE status='pending' ORDER BY ride_id LIMIT 100")->fetchAll(PDO::FETCH_COLUMN);
        foreach($ids as $id){
            try {$worker->process((int)$id);}
            catch(Throwable $e){$failed=true;fwrite(STDERR,"Documento Ride pendiente: se conserva para reintento.\n");}
        }
    } catch(Throwable $e){$failed=true;fwrite(STDERR,"No se pudo consultar trabajo Ride pendiente.\n");}
    if($once)exit($failed?1:0);
    sleep(30);
}while(true);
