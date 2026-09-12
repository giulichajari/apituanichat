<?php

namespace App\Controllers;

use App\Models\WalletModel;
use App\Models\CardModel;
use App\Services\MailService;
use App\Models\WebAuthnModel;
use App\Controllers\WebAuthnController;
use EasyProjects\SimpleRouter\Router;

class WalletController
{
    private WalletModel $walletModel;
    private CardModel $cardModel;
    private WebAuthnModel $webAuthnModel;

    public function __construct()
    {
        $this->walletModel = new WalletModel();
        $this->cardModel = new CardModel();
        $this->webAuthnModel = new WebAuthnModel();
    }

    public function getWallet()
    {
        $userId = Router::$request->user->id ?? null;
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }

        $wallet = $this->walletModel->getOrCreateWallet((int) $userId);
        Router::$response->status(200)->json([
            "balance" => (float) $wallet['balance'],
            "account_number" => $wallet['account_number'],
            "currency" => "USD",
        ]);
    }

    public function createRecharge()
    {
        $userId = Router::$request->user->id ?? null;
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }

        $body = json_decode(file_get_contents('php://input'), true);
        $amount = (float) ($body['amount'] ?? 0);
        $currency = $body['currency'] ?? 'USD';

        if ($amount < 1) {
            Router::$response->status(400)->json(["message" => "Monto mínimo de recarga: 1"]);
            return;
        }

        $feeAmount = round(($amount * 0.029) + 0.30, 2);
        $netAmount = round($amount - $feeAmount, 2);

        if ($netAmount <= 0) {
            Router::$response->status(400)->json(["message" => "El monto es demasiado bajo para cubrir la comisión"]);
            return;
        }

        $square = $this->createSquarePaymentLink($amount, $currency, "Recarga de wallet");
        if (!empty($square['error'])) {
            Router::$response->status(500)->json([
                "message" => "Error creando link de pago",
                "error" => $square['error']
            ]);
            return;
        }

        $rechargeId = $this->walletModel->createRecharge([
            'user_id' => (int) $userId,
            'amount' => $amount,
            'fee_amount' => $feeAmount,
            'net_amount' => $netAmount,
            'currency' => $currency,
            'payment_link_id' => $square['payment_link_id'] ?? null,
            'payment_link_url' => $square['url'],
            'idempotency_key' => $square['idempotency_key'],
        ]);

        Router::$response->status(201)->json([
            "message" => "Link de recarga creado",
            "recharge_id" => $rechargeId,
            "paymentUrl" => $square['url'],
            "amount" => $amount,
            "fee_amount" => $feeAmount,
            "net_amount" => $netAmount,
        ]);
    }

    public function rechargeWithSavedCard()
    {
        $userId = Router::$request->user->id ?? null;
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }

        $body = json_decode(file_get_contents('php://input'), true);
        $cardId = (int) ($body['card_id'] ?? 0);
        $amount = (float) ($body['amount'] ?? 0);
        $currency = $body['currency'] ?? 'USD';

        if (!$cardId || $amount < 1) {
            Router::$response->status(400)->json(["message" => "Datos incompletos o monto inválido"]);
            return;
        }

        $card = $this->cardModel->getCardById($cardId, (int) $userId);
        if (!$card) {
            Router::$response->status(404)->json(["message" => "Tarjeta no encontrada"]);
            return;
        }

        $feeAmount = round(($amount * 0.029) + 0.30, 2);
        $netAmount = round($amount - $feeAmount, 2);
        if ($netAmount <= 0) {
            Router::$response->status(400)->json(["message" => "El monto es demasiado bajo para cubrir la comisión"]);
            return;
        }

        $appEnv = isset($_ENV['APP_ENV']) ? strtolower((string) $_ENV['APP_ENV']) : '';
        $isProd = ($appEnv === 'production');
        $accessToken = $isProd
            ? trim((string) ($_ENV['SQUARE_ACCESS_TOKEN_PROD'] ?? $_ENV['SQUARE_ACCESS_TOKEN'] ?? ''))
            : trim((string) ($_ENV['SQUARE_ACCESS_TOKEN_SANDBOX'] ?? $_ENV['SQUARE_ACCESS_TOKEN'] ?? ''));
        $baseUrl = $isProd ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com';

        $idempotencyKey = uniqid('walletcard_', true);
        $amountCents = (int) round($amount * 100);

        $ch = curl_init($baseUrl . '/v2/payments');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: Bearer $accessToken",
            "Square-Version: 2025-03-19"
        ]);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'idempotency_key' => $idempotencyKey,
            'source_id' => $card['square_card_id'],
            'amount_money' => [
                'amount' => $amountCents,
                'currency' => strtoupper($currency),
            ],
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $result = json_decode($response, true);

        $payment = $result['payment'] ?? null;
        $status = $payment['status'] ?? null;

        if (($httpcode !== 200 && $httpcode !== 201) || !in_array($status, ['COMPLETED', 'APPROVED'], true)) {
            Router::$response->status(400)->json([
                "message" => "No se pudo procesar el cobro",
                "error" => $result['errors'] ?? $result,
            ]);
            return;
        }

        $credit = $this->walletModel->creditRechargeDirect(
            (int) $userId,
            $amount,
            $feeAmount,
            $netAmount,
            $currency,
            (string) $payment['id']
        );

        if (!$credit['success']) {
            Router::$response->status(500)->json(["message" => $credit['message'] ?? 'Error al acreditar saldo']);
            return;
        }

        Router::$response->status(200)->json([
            "message" => "Recarga exitosa",
            "new_balance" => $credit['new_balance'],
            "fee_amount" => $feeAmount,
            "net_amount" => $netAmount,
        ]);
    }

    public function transfer()
    {
        $userId = Router::$request->user->id ?? null;
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }

        $body = json_decode(file_get_contents('php://input'), true);
        $toAccountNumber = trim((string) ($body['account_number'] ?? ''));
        $amount = (float) ($body['amount'] ?? 0);
        $pin = isset($body['pin']) ? (string) $body['pin'] : null;
        $webauthnAssertion = $body['webauthn'] ?? null;

        if (!$toAccountNumber || $amount <= 0) {
            Router::$response->status(400)->json(["message" => "Datos incompletos o monto inválido"]);
            return;
        }

        $hasWebAuthn = $this->webAuthnModel->hasAnyCredential((int) $userId);
        $hasPin = $this->walletModel->isPinEnabled((int) $userId);

        if ($hasWebAuthn || $hasPin) {
            if ($webauthnAssertion) {
                $waController = new WebAuthnController();
                $waResult = $waController->verifyAssertion((int) $userId, $webauthnAssertion);
                if (!$waResult['ok']) {
                    Router::$response->status(400)->json(["message" => $waResult['message']]);
                    return;
                }
            } elseif ($hasPin) {
                if (!$pin) {
                    Router::$response->status(400)->json(["message" => "Se requiere tu PIN para enviar dinero", "pin_required" => true]);
                    return;
                }
                $pinCheck = $this->walletModel->verifyPin((int) $userId, $pin);
                if (!$pinCheck['ok']) {
                    Router::$response->status(!empty($pinCheck['locked']) ? 423 : 400)->json(["message" => $pinCheck['message']]);
                    return;
                }
            } else {
                Router::$response->status(400)->json(["message" => "Se requiere verificación (Face ID/huella)", "webauthn_required" => true]);
                return;
            }
        }

        $result = $this->walletModel->transfer((int) $userId, $toAccountNumber, $amount);

        if (!$result['success']) {
            Router::$response->status(400)->json(["message" => $result['message']]);
            return;
        }

        $this->notifyTransfer((int) $userId, $toAccountNumber, $amount);

        Router::$response->status(200)->json([
            "message" => "Transferencia realizada",
            "new_balance" => $result['new_balance'],
        ]);
    }

    private function notifyTransfer(int $fromUserId, string $toAccountNumber, float $amount): void
    {
        try {
            $fromEmail = $this->walletModel->getUserEmail($fromUserId);
            if ($fromEmail) {
                MailService::send(
                    $fromEmail,
                    'Enviaste dinero desde tu wallet',
                    "Enviaste $" . number_format($amount, 2) . " a la cuenta $toAccountNumber."
                );
            }

            $toWallet = $this->walletModel->getByAccountNumber($toAccountNumber);
            if ($toWallet) {
                $toEmail = $this->walletModel->getUserEmail((int) $toWallet['user_id']);
                if ($toEmail) {
                    MailService::send(
                        $toEmail,
                        'Recibiste dinero en tu wallet',
                        "Recibiste $" . number_format($amount, 2) . " en tu wallet."
                    );
                }
            }
        } catch (\Exception $e) {
            error_log('WalletController::notifyTransfer error: ' . $e->getMessage());
        }
    }

    public function getPinStatus()
    {
        $userId = Router::$request->user->id ?? null;
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }
        Router::$response->status(200)->json(["pin_enabled" => $this->walletModel->isPinEnabled((int) $userId)]);
    }

    public function setPin()
    {
        $userId = Router::$request->user->id ?? null;
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }
        $body = json_decode(file_get_contents('php://input'), true);
        $pin = (string) ($body['pin'] ?? '');

        if (!preg_match('/^\d{4}$/', $pin)) {
            Router::$response->status(400)->json(["message" => "El PIN debe ser de 4 dígitos"]);
            return;
        }

        $ok = $this->walletModel->setPin((int) $userId, $pin);
        Router::$response->status($ok ? 200 : 500)->json([
            "message" => $ok ? "PIN configurado" : "No se pudo configurar el PIN",
        ]);
    }

    public function disablePin()
    {
        $userId = Router::$request->user->id ?? null;
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }
        $ok = $this->walletModel->disablePin((int) $userId);
        Router::$response->status($ok ? 200 : 500)->json([
            "message" => $ok ? "PIN desactivado" : "No se pudo desactivar el PIN",
        ]);
    }

    public function getTransactions()
    {
        $userId = Router::$request->user->id ?? null;
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }

        $transactions = $this->walletModel->getTransactions((int) $userId);
        Router::$response->status(200)->json($transactions);
    }

    public function payWithWallet()
    {
        $userId = Router::$request->user->id ?? null;
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }

        $body = json_decode(file_get_contents('php://input'), true);
        $amount = (float) ($body['amount'] ?? 0);
        $reference = (string) ($body['reference'] ?? 'purchase_' . time());

        if ($amount <= 0) {
            Router::$response->status(400)->json(["message" => "Monto inválido"]);
            return;
        }

        $result = $this->walletModel->debitForPurchase((int) $userId, $amount, $reference);

        if (!$result['success']) {
            Router::$response->status(400)->json(["message" => $result['message']]);
            return;
        }

        Router::$response->status(200)->json([
            "message" => "Pago con wallet exitoso",
            "new_balance" => $result['new_balance'],
        ]);
    }

    /**
     * @return array{url:?string,payment_link_id:?string,idempotency_key:?string,error:?string}
     */
    private function createSquarePaymentLink(float $amount, string $currency, string $name): array
    {
        $appEnv = isset($_ENV['APP_ENV']) ? strtolower((string) $_ENV['APP_ENV']) : '';
        $isProd = ($appEnv === 'production');

        if ($isProd) {
            $accessToken = trim((string) ($_ENV['SQUARE_ACCESS_TOKEN_PROD'] ?? $_ENV['SQUARE_ACCESS_TOKEN'] ?? ''));
            $locationId = (string) ($_ENV['SQUARE_LOCATION_ID_PROD'] ?? $_ENV['SQUARE_LOCATION_ID'] ?? '');
        } else {
            $accessToken = trim((string) ($_ENV['SQUARE_ACCESS_TOKEN_SANDBOX'] ?? $_ENV['SQUARE_ACCESS_TOKEN'] ?? ''));
            $locationId = (string) ($_ENV['SQUARE_LOCATION_ID_SANDBOX'] ?? $_ENV['SQUARE_LOCATION_ID'] ?? '');
        }
        $locationId = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($locationId));

        $sandboxEnv = $_ENV['SQUARE_SANDBOX'] ?? '';
        $sandbox = ($sandboxEnv === 'true' || $sandboxEnv === '1') ? true : !$isProd;
        $squareBaseUrl = $sandbox ? 'https://connect.squareupsandbox.com' : 'https://connect.squareup.com';

        if (empty($accessToken) || empty($locationId)) {
            return [
                'url' => null,
                'payment_link_id' => null,
                'idempotency_key' => null,
                'error' => empty($accessToken) ? 'SQUARE_ACCESS_TOKEN no configurado' : 'SQUARE_LOCATION_ID no configurado'
            ];
        }

        $amountCents = (int) round($amount * 100);
        if ($amountCents <= 0) {
            return ['url' => null, 'payment_link_id' => null, 'idempotency_key' => null, 'error' => 'Monto inválido'];
        }

        $idempotencyKey = uniqid('wallet_', true);
        $postData = [
            "idempotency_key" => $idempotencyKey,
            "quick_pay" => [
                "name" => $name,
                "price_money" => [
                    "amount" => $amountCents,
                    "currency" => strtoupper($currency)
                ],
                "location_id" => $locationId
            ]
        ];

        $ch = curl_init($squareBaseUrl . "/v2/online-checkout/payment-links");
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: Bearer $accessToken",
            "Square-Version: 2025-03-19"
        ]);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($response, true);

        if ($httpcode !== 200 && $httpcode !== 201) {
            return [
                'url' => null,
                'payment_link_id' => null,
                'idempotency_key' => $idempotencyKey,
                'error' => $result ?? "HTTP $httpcode"
            ];
        }

        return [
            'url' => $result['payment_link']['url'] ?? null,
            'payment_link_id' => $result['payment_link']['id'] ?? null,
            'idempotency_key' => $idempotencyKey,
            'error' => null
        ];
    }
}
