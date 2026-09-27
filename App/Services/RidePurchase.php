<?php
namespace App\Services;

use PDO;

/** Candidate HTTP boundary. Caller must supply identity from verified authentication. */
final class RidePurchase
{
    public function __construct(private PDO $db) {}

    public function execute(int $authenticatedUserId, array $body): array
    {
        if ($authenticatedUserId < 1) throw new \InvalidArgumentException('Sesión requerida');
        $key=$body['idempotencyKey']??null;
        if (!is_string($key)||!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$key)) throw new \InvalidArgumentException('Falta el identificador de compra');
        if (!isset($body['confirmedFare'])) throw new \InvalidArgumentException('Confirma el importe antes de pagar');
        $ride=$this->bodyToRide($body);
        $ride['confirmed_fare']=$body['confirmedFare'];
        return (new RideCheckout($this->db))->purchase($authenticatedUserId,$key,$ride,new RideFareQuote($this->db));
    }
    public function quote(int $authenticatedUserId,array $body): array
    {
        return (new RideCheckout($this->db))->quote($authenticatedUserId,$this->bodyToRide($body),new RideFareQuote($this->db));
    }
    private function bodyToRide(array $body): array
    {
        if (!is_array($body['pickup']??null)||!is_array($body['destination']??null)) throw new \InvalidArgumentException('Origen y destino requeridos');
        $type=$body['serviceType']??'passenger';
        $details=$body['packageDetails']??null;
        if ($type==='package') {
            if ($details!==null&&!is_array($details)) throw new \InvalidArgumentException('Descripción de paquete inválida');
            $details=$details??[];
            if (isset($body['packageType'])) {
                if (!is_string($body['packageType'])||mb_strlen($body['packageType'])>100) throw new \InvalidArgumentException('Tipo de contenido inválido');
                $details['type']=$body['packageType'];
            }
        }
        // Ignore userId, estimatedFare, payment status, amount and currency conversion sent by client.
        $ride=[
            'driver_id'=>$body['driverId']??null,
            'pickup_lat'=>$body['pickup']['lat']??null,'pickup_lng'=>$body['pickup']['lng']??null,
            'dest_lat'=>$body['destination']['lat']??null,'dest_lng'=>$body['destination']['lng']??null,
            'pickup_address'=>$body['pickupAddress']??'','dest_address'=>$body['destinationAddress']??'',
            'currency'=>$body['currency']??'USD','service_type'=>$type,
            
            'package_weight_kg'=>$body['packageWeightKg']??null,'package_length_cm'=>$body['packageLengthCm']??null,
            'package_width_cm'=>$body['packageWidthCm']??null,'package_height_cm'=>$body['packageHeightCm']??null,
            // Remis sends vehicle separately from content type (e.g. Small Box).
            'package_type'=>$type==='package'?($body['packageVehicleType']??null):null,
            'package_details'=>$details,
        ];
        if ($type==='package'&&empty($ride['package_type'])) throw new \InvalidArgumentException('Selecciona vehículo para el paquete');
        return $ride;
    }
}
