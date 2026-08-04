<?php

namespace App\Services;

use App\Models\CountryRateModel;
use App\Models\DriverModel;

/**
 * Recalcula tarifas de viaje en el servidor a partir de coords + tarifas de BD.
 * Nunca confiar en estimatedFare enviado por el cliente.
 */
class FareCalculator
{
    private const AVG_SPEED_KMH = 30.0;
    private const MIN_FARE = 1.0;

    public function __construct(
        private ?DriverModel $driverModel = new DriverModel(),
        private ?CountryRateModel $countryRateModel = new CountryRateModel()
    ) {}

    /**
     * @param array{lat?:float|int|string,lng?:float|int|string} $pickup
     * @param array{lat?:float|int|string,lng?:float|int|string} $destination
     * @return array{fare: float, distance_km: float, duration_hours: float, rate: float, pricing_model: string, currency_basis: string}
     */
    public function calculate(
        int $driverId,
        array $pickup,
        array $destination,
        string $serviceType = 'passenger',
        ?string $packageType = null
    ): array {
        $driver = $this->driverModel->getDriver($driverId);
        if (!$driver) {
            throw new \InvalidArgumentException('Conductor no encontrado');
        }

        $lat1 = (float) ($pickup['lat'] ?? 0);
        $lng1 = (float) ($pickup['lng'] ?? 0);
        $lat2 = (float) ($destination['lat'] ?? 0);
        $lng2 = (float) ($destination['lng'] ?? 0);

        if ($lat1 === 0.0 && $lng1 === 0.0 && $lat2 === 0.0 && $lng2 === 0.0) {
            throw new \InvalidArgumentException('Coordenadas inválidas');
        }

        $distanceKm = $this->haversineKm($lat1, $lng1, $lat2, $lng2);
        $durationHours = max($distanceKm / self::AVG_SPEED_KMH, 1 / 60); // mínimo 1 minuto

        $rateInfo = $this->resolveRate($driver, $serviceType, $packageType);
        $rate = $rateInfo['rate'];
        $pricingModel = $rateInfo['pricing_model'];

        if ($pricingModel === 'hourly') {
            // Mínimo 1 hora de tarifa para modelo horario
            $billableHours = max(1.0, ceil($durationHours * 2) / 2); // redondeo a 0.5 h, mín 1 h
            $fare = $billableHours * $rate;
        } else {
            $fare = max(self::MIN_FARE, $distanceKm * $rate);
        }

        return [
            'fare' => round($fare, 2),
            'distance_km' => round($distanceKm, 3),
            'duration_hours' => round($durationHours, 4),
            'rate' => $rate,
            'pricing_model' => $pricingModel,
            'currency_basis' => $rateInfo['code'] ?? '',
        ];
    }

    private function resolveRate(array $driver, string $serviceType, ?string $packageType): array
    {
        $countryCode = $this->normalizeCountryCode((string) ($driver['pais'] ?? ''));
        $countryRate = $countryCode ? $this->countryRateModel->getByAlpha2($countryCode) : null;

        // Si pais es ISO-2 no encontrado, intentar por nombre/código de 3 letras vía listado activo
        if (!$countryRate && !empty($driver['pais'])) {
            $countryRate = $this->findRateByCountryHint((string) $driver['pais']);
        }

        $pricingModel = $countryRate['pricing_model'] ?? 'per_km';
        $code = $countryRate['code_alpha2'] ?? ($countryCode ?: '');

        if ($serviceType === 'package' && $countryRate) {
            $packageRate = $this->packageRateFromType($countryRate, $packageType);
            if ($packageRate !== null && $packageRate > 0) {
                return [
                    'rate' => (float) $packageRate,
                    'pricing_model' => 'per_km',
                    'code' => $code,
                ];
            }
        }

        // Preferir preciokm del conductor si está en el rango del país; si no, tarifa de país
        $driverPrice = (float) ($driver['preciokm'] ?? 0);
        $min = (float) ($countryRate['passenger_rate_min'] ?? 0);
        $max = (float) ($countryRate['passenger_rate_max'] ?? 0);

        if ($driverPrice > 0 && $pricingModel === 'per_km') {
            if ($min > 0 && $max > 0) {
                $rate = min(max($driverPrice, $min), $max);
            } else {
                $rate = $driverPrice;
            }
        } elseif ($min > 0 || $max > 0) {
            // Tarifa media del país (o min si son iguales)
            $rate = $max > 0 && $min > 0 ? ($min + $max) / 2 : max($min, $max);
        } elseif ($driverPrice > 0) {
            $rate = $driverPrice;
        } else {
            throw new \RuntimeException('No hay tarifa configurada para este conductor/país');
        }

        // En modelo horario, preciokm del driver no aplica: usar tarifa del país
        if ($pricingModel === 'hourly') {
            $rate = $max > 0 && $min > 0 ? ($min + $max) / 2 : max($min, $max, $rate);
        }

        return [
            'rate' => (float) $rate,
            'pricing_model' => $pricingModel,
            'code' => $code,
        ];
    }

    private function packageRateFromType(array $countryRate, ?string $packageType): ?float
    {
        $type = strtolower(trim((string) $packageType));
        $map = [
            'moto' => 'package_moto_rate',
            'motorcycle' => 'package_moto_rate',
            'auto' => 'package_auto_rate',
            'car' => 'package_auto_rate',
            'camioneta' => 'package_camioneta_rate',
            'suv' => 'package_camioneta_rate',
            'carga' => 'package_carga_rate',
            'cargo' => 'package_carga_rate',
            'truck' => 'package_carga_rate',
        ];

        $field = $map[$type] ?? 'package_auto_rate';
        $value = $countryRate[$field] ?? null;

        return $value !== null && $value !== '' ? (float) $value : null;
    }

    private function findRateByCountryHint(string $hint): ?array
    {
        $hintNorm = strtoupper(trim($hint));
        $all = $this->countryRateModel->getAllActive();

        foreach ($all as $row) {
            $alpha2 = strtoupper((string) ($row['code_alpha2'] ?? ''));
            $code = strtoupper((string) ($row['country_code'] ?? ''));
            $name = strtoupper((string) ($row['country_name'] ?? ''));

            if ($hintNorm === $alpha2 || $hintNorm === $code || $hintNorm === $name) {
                return $row;
            }

            if ($name !== '' && (str_contains($name, $hintNorm) || str_contains($hintNorm, $name))) {
                return $row;
            }
        }

        return null;
    }

    private function normalizeCountryCode(string $pais): string
    {
        $p = strtoupper(trim($pais));
        if (strlen($p) === 2 && ctype_alpha($p)) {
            return $p;
        }

        // Códigos comunes de 3 letras
        $map3 = [
            'USA' => 'US', 'MEX' => 'MX', 'ARG' => 'AR', 'BOL' => 'BO', 'PRY' => 'PY',
            'BRA' => 'BR', 'PER' => 'PE', 'COL' => 'CO', 'CHL' => 'CL', 'NIC' => 'NI',
            'SLV' => 'SV', 'ECU' => 'EC', 'HND' => 'HN', 'CRI' => 'CR', 'PAN' => 'PA',
            'VEN' => 'VE', 'URY' => 'UY',
        ];

        if (isset($map3[$p])) {
            return $map3[$p];
        }

        return '';
    }

    public function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
