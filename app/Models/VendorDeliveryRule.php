<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorDeliveryRule extends Model
{
    public const string DEFAULT_VENDOR = '*';

    protected $fillable = [
        'vendor',
        'enabled',
        'in_stock_delivery_text',
        'backorder_delivery_text',
        'allow_backorder_export',
        'priority',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'allow_backorder_export' => 'boolean',
            'priority' => 'integer',
        ];
    }

    public function isDefault(): bool
    {
        return $this->vendor === self::DEFAULT_VENDOR;
    }
}
