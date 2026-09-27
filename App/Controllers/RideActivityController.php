<?php
namespace App\Controllers;
use App\Configs\Database;
use App\Services\OrderLifecycle;
use EasyProjects\SimpleRouter\Router;
use PDO;

final class RideActivityController
{
    public function __construct(private ?PDO $db=null) {}
    public function history():void {$this->handle('history');}
    public function transition():void {$this->handle('transition');}
    public function handle(string $operation,?string $raw=null):void
    {
        $userId=filter_var(Router::$request->user->id??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if(!$userId){Router::$response->status(401)->json(['message'=>'Inicia sesión']);return;}
        try {
            $db=$this->db??Database::getInstance()->getConnection();
            if($operation==='history'){
                $q=$db->prepare("SELECT o.id,o.status,o.estimated_fare AS total,o.created_at,o.pickup_address,o.dest_address,s.status AS settlement_status,'ride' AS type,CASE WHEN o.driver_id=? THEN 'driver' ELSE 'buyer' END AS role FROM ride_requests o LEFT JOIN commerce_settlements s ON s.reference=CONCAT('ride_',o.id) WHERE o.user_id=? OR o.driver_id=? ORDER BY CASE WHEN o.status IN ('pending','accepted') THEN 0 ELSE 1 END,o.id DESC LIMIT 100");
                $q->execute([$userId,$userId,$userId]);$result=['data'=>$q->fetchAll(PDO::FETCH_ASSOC)];
            } elseif($operation==='transition'){
                $raw=$raw??file_get_contents('php://input');
                if(strlen($raw)>2048)throw new \InvalidArgumentException('Solicitud inválida');
                $body=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
                if(!is_array($body))throw new \InvalidArgumentException('Solicitud inválida');
                $id=filter_var($body['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                if(!$id||!in_array($body['action']??null,['accept','reject','cancel','complete'],true))throw new \InvalidArgumentException('Acción inválida');
                $result=(new OrderLifecycle($db))->transition('ride',$id,$body['action'],$userId);
            } else throw new \InvalidArgumentException('Acción inválida');
        } catch(\JsonException|\InvalidArgumentException $e){
            Router::$response->status(400)->json(['message'=>'Solicitud de viaje inválida']);return;
        } catch(\DomainException $e){
            Router::$response->status(409)->json(['message'=>'No puedes realizar esa acción en el estado actual del viaje. Actualiza el historial.']);return;
        } catch(\Throwable $e){
            error_log('Ride activity failed');
            Router::$response->status(503)->json(['message'=>'No se pudo confirmar el resultado. Actualiza el historial antes de reintentar.']);return;
        }
        Router::$response->status(200)->json($result);
    }
}
