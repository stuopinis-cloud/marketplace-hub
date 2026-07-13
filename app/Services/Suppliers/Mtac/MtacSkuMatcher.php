<?php

namespace App\Services\Suppliers\Mtac;

use App\Services\Suppliers\SupplierSkuMatcher;

class MtacSkuMatcher extends SupplierSkuMatcher
{
    public const string VENDOR = 'M-Tac';

    public function __construct()
    {
        parent::__construct([self::VENDOR]);
    }
}
