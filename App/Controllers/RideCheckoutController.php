<?php
namespace App\Controllers;
use App\Configs\Database;
use App\Services\RidePurchase;
use EasyProjects\SimpleRouter\Router;
use PDO;

/** Candidate routes require TokenMiddleware::strict before invoking either method. */
final class RideCheckoutController
{
    public function __construct(private ?PDO $db=null) {}
    public function quote(): void {$this->handle('quote');}
    public function purchase(): void {$this->handle('purchase');}
    public function handle(string $action,?string $raw=null): void
    {
        $userId=filter_var(Router::$request->user->id??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if(!$userId){Router::$response->status(401)->json(['message'=>'Inicia sesión para continuar']);return;}
        try {
            if(!in_array($action,['quote','purchase'],true))throw new \InvalidArgumentException('Acción inválida');
            $raw=$raw??file_get_contents('php://input');
            if(strlen($raw)>16384)throw new \InvalidArgumentException('Solicitud demasiado grande');
            $decoded=json_decode($raw,false,32,JSON_THROW_ON_ERROR);
            if(!$decoded instanceof \stdClass)throw new \InvalidArgumentException('Se requiere un objeto JSON');
            $body=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
            $service=new RidePurchase($this->db??Database::getInstance()->getConnection());
            $result=$action==='quote'?$service->quote($userId,$body):$service->execute($userId,$body);
        } catch(\JsonException|\InvalidArgumentException $e) {
            Router::$response->status(400)->json(['message'=>'Revisa los datos y confirma la tarifa','code'=>'invalid_request']);
            return;
        } catch(\DomainException $e) {
            Router::$response->status(409)->json(['message'=>'La tarifa cambió. Consulta y confirma el nuevo precio.','code'=>'fare_changed']);
            return;
        } catch(\PDOException $e) {
            error_log('Ride checkout database failure');
            Router::$response->status(503)->json(['message'=>'No se pudo confirmar el resultado. Reintenta la misma solicitud.','code'=>'retry_same_request']);
            return;
        } catch(\RuntimeException $e) {
            Router::$response->status(409)->json(['message'=>'No se pudo completar el viaje. Revisa saldo y disponibilidad.','code'=>'ride_unavailable']);
            return;
        } catch(\Throwable $e) {
            error_log('Ride checkout unexpected failure');
            Router::$response->status(500)->json(['message'=>'No se pudo confirmar el resultado. Reintenta la misma solicitud.','code'=>'retry_same_request']);
            return;
        }
        // A transport failure after commit must never become a definitive rejection.
        Router::$response->status($action==='quote'||!empty($result['replayed'])?200:201)->json($result);
    }
}
