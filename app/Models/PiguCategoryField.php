<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PiguCategoryField extends Model
{
    protected $fillable = [
        'lowest_category_id',
        'lowest_category_title',
        'lowest_category_title_en',
        'top_category_title',
        'top_category_title_en',
        'field_id',
        'field_title',
        'field_title_en',
        'mandatory',
        'field_value_type',
        'lt_values',
        'lv_values',
        'ee_values',
        'fi_values',
        'en_values',
    ];

    protected function casts(): array
    {
        return [
            'lowest_category_id' => 'integer',
            'field_id' => 'integer',
            'mandatory' => 'boolean',
        ];
    }

    /**
     * @return list<string>
     */
    public function allowedLtValues(): array
    {
        if (blank($this->lt_values)) {
            return [];
        }

        return collect(explode(';', (string) $this->lt_values))
            ->map(fn (string $value): string => trim($value))
            ->filter()
            ->values()
            ->all();
    }
}
