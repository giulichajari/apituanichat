<?php
namespace App\Services;

/** Platform prices, not an estimate of the processor's actual charges. */
final class PlatformFees
{
    public const RECHARGE_BPS = 300;
    public const SELLER_BPS = 500;

    public static function split(int $grossCents, int $basisPoints): array
    {
        if ($grossCents <= 0 || $basisPoints < 0 || $basisPoints > 10000) throw new \InvalidArgumentException('Importe o comisión inválidos');
        $fee = intdiv($grossCents * $basisPoints + 5000, 10000);
        return ['gross_cents' => $grossCents, 'fee_cents' => $fee, 'net_cents' => $grossCents - $fee];
    }
}
