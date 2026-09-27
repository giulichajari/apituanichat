<?php
namespace App\Services;
final class AnuncioPurchaseException extends \RuntimeException
{
    public function __construct(public readonly string $reason,public readonly int $httpStatus) {parent::__construct($reason);}
}
