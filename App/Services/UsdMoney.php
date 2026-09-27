<?php
namespace App\Services;

use InvalidArgumentException;

/** USD values are validated before conversion; sub-cent amounts are never rounded. */
final class UsdMoney
{
    public const MAX_CENTS = 999999999999;

    public static function cents(mixed $value, bool $allowZero = false): int
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException('Ingresa un monto válido en USD.');
        }
        $text = (string) $value;
        if (!preg_match('/^(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?$/D', $text, $parts)) {
            throw new InvalidArgumentException('El monto debe tener como máximo dos decimales.');
        }
        $cents = ((int) $parts[1] * 100) + (int) str_pad($parts[2] ?? '', 2, '0');
        if ($cents > self::MAX_CENTS || (!$allowZero && $cents === 0)) {
            throw new InvalidArgumentException('Monto fuera del rango permitido.');
        }
        return $cents;
    }

    public static function decimal(int $cents): string
    {
        if ($cents < 0 || $cents > self::MAX_CENTS) {
            throw new InvalidArgumentException('Saldo fuera del rango permitido.');
        }
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function requireUsd(mixed $currency): void
    {
        if (!is_string($currency) || strtoupper($currency) !== 'USD') {
            throw new InvalidArgumentException('La Wallet solo opera en USD. La conversión de tu tarjeta la realiza su emisor.');
        }
    }
}
