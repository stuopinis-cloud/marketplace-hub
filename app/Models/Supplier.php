<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $fillable = [
        'name',
        'code',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public function supplierProducts(): HasMany
    {
        return $this->hasMany(SupplierProduct::class);
    }
}
