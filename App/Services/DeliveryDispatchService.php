<?php

namespace App\Services;

use App\Models\DriverModel;
use App\Models\DeviceTokenModel;

// Servicio compartido de despacho a conductores cercanos, usado por Eats y
// Shop (envio local). Ride ya tiene su propio flujo directo en
// DriverController::requestDriver, este servicio no lo reemplaza.
class DeliveryDispatchService
{
    private DriverModel $driverModel;
    private DeviceTokenModel $deviceTokenModel;

    public function __construct(?DriverModel $driverModel = null, ?DeviceTokenModel $deviceTokenModel = null)
    {
        $this->driverModel = $driverModel ?? new DriverModel();
        $this->deviceTokenModel = $deviceTokenModel ?? new DeviceTokenModel();
    }

    /**
     * Busca conductores cercanos al punto de retiro y les notifica el
     * despacho (push FCM). $tipo: 'comida' | 'paquete' | 'persona'.
     * $referenceType/$referenceId identifican el pedido de origen
     * ('eats'/food_order id, 'shop'/shop_order id) para que el conductor
     * sepa que aceptar despues.
     *
     * @return array{notified: int[], driversFound: int}
     */
    public function dispatchToNearbyDrivers(
        float $pickupLat,
        float $pickupLng,
        string $pickupAddress,
        ?float $destLat,
        ?float $destLng,
        string $destAddress,
        float $price,
        string $tipo,
        string $referenceType,
        int $referenceId,
        float $radiusKm = 10,
        int $maxDrivers = 5
    ): array {
        $drivers = $this->driverModel->findNearbyAvailableDrivers($pickupLat, $pickupLng, $radiusKm, $maxDrivers);

        $notified = [];

        foreach ($drivers as $driver) {
            $driverId = (int) $driver['user_id'];
            $tokens = $this->deviceTokenModel->getActiveTokensForUser($driverId);

            $anySent = false;
            foreach ($tokens as $row) {
                $fcmToken = $row['fcm_token'] ?? null;
                if (!$fcmToken) {
                    continue;
                }
                $ok = FcmService::sendDataMessage($fcmToken, [
                    'type' => 'delivery_dispatch',
                    'delivery_type' => $tipo,
                    'reference_type' => $referenceType,
                    'reference_id' => (string) $referenceId,
                    'message' => 'Nuevo pedido de ' . $this->tipoLabel($tipo) . ' cerca tuyo',
                    'price' => (string) $price,
                    'pickup_address' => $pickupAddress,
                    'dest_address' => $destAddress,
                    'distance_km' => (string) round((float) ($driver['distance_km'] ?? 0), 1),
                ]);
                if ($ok) {
                    $anySent = true;
                } else {
                    $this->deviceTokenModel->deactivateToken($fcmToken);
                }
            }

            if ($anySent) {
                $notified[] = $driverId;
            }
        }

        return [
            'notified' => $notified,
            'driversFound' => count($drivers),
        ];
    }

    private function tipoLabel(string $tipo): string
    {
        return match ($tipo) {
            'comida' => 'comida',
            'paquete' => 'un paquete',
            'persona' => 'un pasajero',
            default => 'un servicio',
        };
    }
}
