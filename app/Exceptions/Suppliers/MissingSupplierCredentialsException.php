<?php

namespace App\Exceptions\Suppliers;

use RuntimeException;

class MissingSupplierCredentialsException extends RuntimeException
{
    public function __construct(string $supplierCode)
    {
        parent::__construct('missing_supplier_credentials: Supplier credentials are not configured for '.$supplierCode.'.');
    }
}
