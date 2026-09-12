<?php
// Script CLI: procesa pagos programados vencidos.
// Se ejecuta diariamente via crontab del sistema, no via HTTP.

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Models\ScheduledPaymentModel;
use App\Models\WalletModel;
use App\Models\UsersModel;
use App\Services\MailService;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$scheduledModel = new ScheduledPaymentModel();
$walletModel = new WalletModel();
$usersModel = new UsersModel();

$pagos = $scheduledModel->getDueForToday();
echo "[" . date('Y-m-d H:i:s') . "] Procesando " . count($pagos) . " pagos programados vencidos.\n";

foreach ($pagos as $pago) {
    $userId = (int) $pago['user_id'];
    $amount = (float) $pago['amount'];
    $reference = "scheduled_payment_{$pago['id']}_{$pago['service_type']}";

    $result = $walletModel->debitForPurchase($userId, $amount, $reference);

    if ($result['success']) {
        $scheduledModel->markCompleted((int) $pago['id']);
        echo "  OK  pago #{$pago['id']} usuario {$userId} monto {$amount}\n";
        continue;
    }

    $scheduledModel->markFailed((int) $pago['id']);
    echo "  FAIL pago #{$pago['id']} usuario {$userId}: {$result['message']}\n";

    $user = $usersModel->getUser($userId);
    if ($user && !empty($user['email'])) {
        $body = "Hola,\n\nNo pudimos procesar tu pago programado de \${$amount} "
            . "para el servicio '{$pago['service_type']}' (ID #{$pago['id']}).\n"
            . "Motivo: {$result['message']}\n\n"
            . "Por favor recarga tu wallet o contacta a soporte.\n\nTuanichat";
        MailService::send(
            $user['email'],
            "Pago programado fallido - Tuanichat",
            $body,
            'Tuanichat',
            false
        );
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Proceso finalizado.\n";
