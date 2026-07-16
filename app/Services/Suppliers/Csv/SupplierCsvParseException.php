<?php

namespace App\Services\Suppliers\Csv;

use RuntimeException;
use Throwable;

class SupplierCsvParseException extends RuntimeException
{
    public function __construct(
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
