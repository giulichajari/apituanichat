<?php
namespace App\Services;

use App\Configs\Database;
use App\Models\CountryRateModel;
use App\Models\DriverModel;
use PDO;

/** Uses existing pricing rules without schema creation/seeding during checkout. */
final class RideFareQuote
{
    private FareCalculator $calculator;
    public function __construct(private PDO $db)
    {
        if (Database::getInstance()->getConnection() !== $db) {
            throw new \RuntimeException('La tarifa requiere la misma conexión del cobro');
        }
        $rates = new class extends CountryRateModel {
            public function ensureSchemaAndSeed(): void
            {
                // Schema preparation belongs to installation, never a financial transaction.
                // Inherited SELECTs fail closed if the required schema is missing.
            }
        };
        $this->calculator = new FareCalculator(new DriverModel(), $rates);
    }

    public function __invoke(array $intent): array
    {
        if (!$this->db->inTransaction()) throw new \RuntimeException('Cotización de cobro fuera de transacción');
        $result = $this->calculator->calculate(
            $intent['driver_id'],
            ['lat'=>$intent['pickup_lat'],'lng'=>$intent['pickup_lng']],
            ['lat'=>$intent['dest_lat'],'lng'=>$intent['dest_lng']],
            $intent['service_type'], $intent['package_type']
        );
        if (!$this->db->inTransaction()) throw new \RuntimeException('La cotización interrumpió la transacción');
        UsdMoney::cents($result['fare']);
        return $result;
    }
}
