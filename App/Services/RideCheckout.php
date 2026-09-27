<?php
namespace App\Services;
use App\Models\WalletModel;
use PDO;

/** Candidate domain operation. No HTTP route uses this until integration is verified. */
final class RideCheckout
{
    public function __construct(private PDO $db) {}

    // $quote must be a server-owned fare calculator, never a callback or amount from HTTP.
    public function purchase(int $userId, string $key, array $ride, callable $quote): array
    {
        $engines=$this->db->query('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach(['ride_requests','payments','drivers','driver_applications','wallets','wallet_transactions','wallet_checkout_requests','family_links','commerce_settlements','push_outbox','ride_documents']as$table){
            if(($engines[$table]??'')!=='InnoDB')throw new \RuntimeException('Ride requiere tablas transaccionales');
        }
        $intent=self::normalize($userId,$ride);
        $driverId=$intent['driver_id'];
        $checkout=new WalletCheckout($this->db);
        return $checkout->run($userId,'ride',$key,$intent,function()use($userId,$key,$intent,$quote,$driverId){
            $q=$this->db->prepare('SELECT id,is_available FROM drivers WHERE user_id=? FOR UPDATE');$q->execute([$driverId]);$drivers=$q->fetchAll(PDO::FETCH_ASSOC);
            if(count($drivers)!==1||(int)$drivers[0]['is_available']!==1)throw new \RuntimeException('Conductor no disponible');
            $q=$this->db->prepare("SELECT id FROM driver_applications WHERE user_id=? AND status='approved' LIMIT 1 FOR UPDATE");$q->execute([$driverId]);
            if(!$q->fetchColumn())throw new \RuntimeException('Conductor no aprobado');
            $fare=$quote($intent);
            $cents=UsdMoney::cents($fare['fare']??null);
            if($cents>9999999999)throw new \InvalidArgumentException('Tarifa fuera de límites');
            $amount=UsdMoney::decimal($cents);
            if (isset($intent['confirmed_fare']) && $intent['confirmed_fare'] !== $amount) {
                throw new \DomainException('La tarifa cambió. Confirma el nuevo importe antes de pagar.');
            }
            $reference='ride:'.hash('sha256',$userId.':'.$key);
            $wallet=new WalletModel($this->db);
            $debit=$wallet->debitForPurchase($userId,(float)$amount,$reference);
            if(empty($debit['success']))throw new \RuntimeException($debit['message']??'No se pudo debitar');
            $values=$intent;unset($values['currency'],$values['confirmed_fare']);$values['user_id']=$userId;$values['estimated_fare']=$amount;$values['status']='pending';
            $columns=implode(',',array_map(fn($k)=>'`'.$k.'`',array_keys($values)));
            $this->db->prepare('INSERT INTO ride_requests ('.$columns.',created_at) VALUES ('.implode(',',array_fill(0,count($values),'?')).',CURRENT_TIMESTAMP)')->execute(array_values($values));
            $rideId=(int)$this->db->lastInsertId();
            $this->db->prepare("INSERT INTO payments(user_id,driver_id,ride_request_id,amount,currency,status,payment_method,idempotency_key,transaction_id,created_at) VALUES(?,?,?,?,'USD','completed','wallet',?,?,CURRENT_TIMESTAMP)")->execute([$userId,$driverId,$rideId,$amount,$reference,(string)$debit['transaction_id']]);
            $paymentId=(int)$this->db->lastInsertId();
            (new CommerceSettlement($this->db))->hold('ride_'.$rideId,(int)$debit['transaction_id'],$driverId);
            $this->db->prepare('UPDATE drivers SET is_available=0 WHERE id=?')->execute([$drivers[0]['id']]);
            RidePush::enqueue($this->db,$rideId);
            (new RideDocuments($this->db))->enqueue($rideId);
            return ['rideRequestId'=>$rideId,'payment_id'=>$paymentId,'estimatedFare'=>$amount,'fareDetails'=>$fare,'newBalance'=>$debit['new_balance'],'wallet_transaction_id'=>$debit['transaction_id'],'via_family'=>(bool)$debit['via_family']];
        });
    }
    public static function normalize(int $userId,array $ride): array
    {
        $driverId=filter_var($ride['driver_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($userId<1||!$driverId||$driverId===$userId)throw new \InvalidArgumentException('Participantes inválidos');
        UsdMoney::requireUsd($ride['currency']??'USD');
        $type=$ride['service_type']??'passenger';
        if(!in_array($type,['passenger','package'],true))throw new \InvalidArgumentException('Servicio inválido');
        $intent=['driver_id'=>$driverId,'currency'=>'USD','service_type'=>$type];
        if (array_key_exists('confirmed_fare',$ride)) $intent['confirmed_fare']=UsdMoney::decimal(UsdMoney::cents($ride['confirmed_fare']));
        foreach(['pickup_lat'=>90,'pickup_lng'=>180,'dest_lat'=>90,'dest_lng'=>180]as$field=>$limit){
            $v=$ride[$field]??null;
            if((!is_string($v)&&!is_int($v)&&!is_float($v))||!is_numeric($v)||!is_finite((float)$v)||abs((float)$v)>$limit)throw new \InvalidArgumentException('Coordenadas inválidas');
            $intent[$field]=number_format((float)$v,8,'.','');
        }
        foreach(['pickup_address','dest_address']as$field){$v=$ride[$field]??'';if(!is_string($v)||mb_strlen($v,'UTF-8')>255)throw new \InvalidArgumentException('Dirección inválida');$intent[$field]=$v;}
        foreach(['package_weight_kg','package_length_cm','package_width_cm','package_height_cm']as$field){
            $v=$type==='package'?($ride[$field]??null):null;
            if($type==='package'){
                $cents=UsdMoney::cents($v);
                if($cents>9999999999||($field==='package_weight_kg'&&$cents>1000))throw new \InvalidArgumentException('Paquete fuera de límites');
                $v=UsdMoney::decimal($cents);
            }
            $intent[$field]=$v;
        }
        $packageType=$type==='package'?($ride['package_type']??'auto'):null;
        if($packageType!==null&&(!is_string($packageType)||!in_array($packageType,['moto','motorcycle','auto','car','camioneta','suv','carga','cargo','truck'],true)))throw new \InvalidArgumentException('Tipo de paquete inválido');
        $intent['package_type']=$packageType;
        $details=$type==='package'?($ride['package_details']??null):null;
        $json=$details===null?null:json_encode($details,JSON_THROW_ON_ERROR);
        if($json!==null&&strlen($json)>4096)throw new \InvalidArgumentException('Descripción demasiado grande');
        $intent['package_details']=$json;
        return $intent;
    }

    public function quote(int $userId,array $ride,callable $calculator): array
    {
        unset($ride['confirmed_fare']);
        $intent=self::normalize($userId,$ride);
        if($this->db->inTransaction())throw new \RuntimeException('Transacción ya iniciada');
        $this->db->beginTransaction();
        try {
            $q=$this->db->prepare('SELECT id,is_available FROM drivers WHERE user_id=? FOR UPDATE');
            $q->execute([$intent['driver_id']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
            if(count($rows)!==1||(int)$rows[0]['is_available']!==1)throw new \RuntimeException('Conductor no disponible');
            $q=$this->db->prepare("SELECT id FROM driver_applications WHERE user_id=? AND status='approved' LIMIT 1");
            $q->execute([$intent['driver_id']]);
            if(!$q->fetchColumn())throw new \RuntimeException('Conductor no aprobado');
            $fare=$calculator($intent);
            $cents=UsdMoney::cents($fare['fare']??null);
            if($cents>9999999999)throw new \InvalidArgumentException('Tarifa fuera de límites');
            return ['estimatedFare'=>UsdMoney::decimal($cents),'currency'=>'USD','fareDetails'=>$fare];
        } finally {
            if($this->db->inTransaction())$this->db->rollBack();
        }
    }
}
