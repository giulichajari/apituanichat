<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
use RuntimeException;
final class SquareCheckoutContract
{
    public static function payload(array $variation, string $id, string $location, int $cents, string $key): array
    {
        $v = $variation['subscription_plan_variation_data'] ?? [];
        $phases = $v['phases'] ?? [];
        if (($variation['id'] ?? '') !== $id || ($variation['type'] ?? '') !== 'SUBSCRIPTION_PLAN_VARIATION'
            || !empty($variation['is_deleted']) || count($phases) !== 1) throw new RuntimeException('variation_mismatch');
        $p = $phases[0];
        if (($p['cadence'] ?? '') !== 'MONTHLY' || ($p['pricing']['type'] ?? '') !== 'STATIC'
            || isset($p['periods']) || ($p['pricing']['price']['currency'] ?? '') !== 'USD'
            || ($p['pricing']['price']['amount'] ?? null) !== $cents || $cents < 100 || $cents > 10000000)
            throw new RuntimeException('price_or_cadence_mismatch');
        if ($location === '' || !preg_match('/^[a-f0-9]{40}$/D', $key)) throw new RuntimeException('request_invalid');
        return ['idempotency_key' => $key,
            'description' => 'TuaniChat Empresa - contratación de prueba Sandbox',
            'quick_pay' => ['name' => 'TuaniChat Empresa PRUEBA - mensual USD', 'location_id' => $location,
                'price_money' => ['amount' => $cents, 'currency' => 'USD']],
            'checkout_options' => ['subscription_plan_id' => $id, 'allow_tipping' => false]];
    }

    public static function link(array $link): array
    {
        $url = $link['url'] ?? '';
        $u = is_string($url) ? parse_url($url) : false;
        if (!$u || ($u['scheme'] ?? '') !== 'https' || ($u['host'] ?? '') !== 'sandbox.square.link'
            || isset($u['user']) || isset($u['pass']) || isset($u['port']) || isset($u['query']) || isset($u['fragment'])
            || !preg_match('~^/u/[A-Za-z0-9_-]+$~D', $u['path'] ?? '')) throw new RuntimeException('checkout_url_invalid');
        foreach (['id', 'order_id'] as $k) if (!is_string($link[$k] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,192}$/D', $link[$k])) throw new RuntimeException('link_invalid');
        return ['id' => $link['id'], 'order_id' => $link['order_id'], 'url' => $url];
    }

    public static function order(array $order, string $id, string $location, int $cents): void
    {
        if (($order['id'] ?? '') !== $id || ($order['location_id'] ?? '') !== $location
            || ($order['total_money']['amount'] ?? null) !== $cents || ($order['total_money']['currency'] ?? '') !== 'USD')
            throw new RuntimeException('order_mismatch');
    }

    public static function intent(?array $existing, array $context, array $payload): array
    {
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        if ($existing !== null) {
            if (($existing['context'] ?? null) !== $context || ($existing['request_hash'] ?? '') !== $hash)
                throw new RuntimeException('checkout_changed');
            return $existing;
        }
        return ['version' => 1, 'context' => $context, 'request_hash' => $hash, 'link' => null];
    }
}
