<?php
namespace App\Services;
final class MonetizacionException extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $status) { parent::__construct($reason); }
}
