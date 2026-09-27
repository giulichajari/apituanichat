<?php
namespace App\Services;

use InvalidArgumentException;

final class MonetizacionInput
{
    public const CATEGORIES = ['entretenimiento','musica','gaming','noticias','educacion','deportes','tecnologia','negocios','arte','estilo_de_vida','otros'];

    private static function text(mixed $value, int $min, int $max, string $error): string
    {
        if (!is_string($value) || !preg_match('//u', $value)) throw new InvalidArgumentException($error);
        $value = trim($value);
        if (preg_match('/[\x00-\x1F\x7F]/u', $value)) throw new InvalidArgumentException($error);
        $length = preg_match_all('/./us', $value);
        if ($length < $min || $length > $max) throw new InvalidArgumentException($error);
        return $value;
    }

    public static function application(array $input): array
    {
        $category = $input['categoria_contenido'] ?? null;
        if (!is_string($category) || !in_array($category, self::CATEGORIES, true)) throw new InvalidArgumentException('monetizacion.errors.category');
        $name = self::text($input['nombre_completo'] ?? null, 2, 160, 'monetizacion.errors.name');
        $country = $input['pais'] ?? null;
        if (!is_string($country) || !preg_match('/^[A-Za-z]{2,3}$/D', $country)) throw new InvalidArgumentException('monetizacion.errors.country');
        $contact = self::text($input['contacto'] ?? null, 3, 190, 'monetizacion.errors.contact');
        $phone = preg_replace('/[ ().-]/', '', $contact);
        if (!filter_var($contact, FILTER_VALIDATE_EMAIL) && !preg_match('/^\+?[0-9]{7,15}$/D', $phone)) throw new InvalidArgumentException('monetizacion.errors.contact');
        try { $cents = UsdMoney::cents($input['monto_deseado'] ?? null); }
        catch (InvalidArgumentException $e) { throw new InvalidArgumentException('monetizacion.errors.amount'); }
        if ($cents > 10000000) throw new InvalidArgumentException('monetizacion.errors.amount');
        return ['categoria_contenido'=>$category, 'nombre_completo'=>$name, 'pais'=>strtoupper($country), 'contacto'=>$contact, 'monto_deseado'=>UsdMoney::decimal($cents)];
    }

    public static function review(array $input): array
    {
        $state = $input['estado'] ?? null;
        if (!in_array($state, ['aprobado','rechazado'], true)) throw new InvalidArgumentException('monetizacion.errors.review');
        $reason = $input['motivo_rechazo'] ?? '';
        if ($reason === null) $reason = '';
        $reason = self::text($reason, 0, 1000, 'monetizacion.errors.reason');
        return ['estado'=>$state,'motivo_rechazo'=>$state === 'rechazado' && $reason !== '' ? $reason : null];
    }
}
