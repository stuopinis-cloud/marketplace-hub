<?php

namespace App\Models;

use App\Enums\MarketplaceTranslationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MarketplaceTranslation extends Model
{
    public const string FIELD_TITLE = 'title';

    public const string FIELD_DESCRIPTION = 'description';

    public const string FIELD_ATTRIBUTE_NAME = 'attribute_name';

    public const string FIELD_ATTRIBUTE_VALUE = 'attribute_value';

    public const string FIELD_OPTION_NAME = 'option_name';

    public const string FIELD_OPTION_VALUE = 'option_value';

    public const string FIELD_CATEGORY_PATH = 'category_path';

    public const string FIELD_DELIVERY_TEXT = 'delivery_text';

    protected $fillable = [
        'translatable_type',
        'translatable_id',
        'marketplace',
        'locale',
        'field',
        'source_text_hash',
        'source_text',
        'translated_text',
        'status',
        'provider',
        'error_message',
        'translated_at',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => MarketplaceTranslationStatus::class,
            'translated_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function translatable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isUsable(): bool
    {
        return in_array($this->status, [
            MarketplaceTranslationStatus::Approved,
            MarketplaceTranslationStatus::Reviewed,
            MarketplaceTranslationStatus::AutoTranslated,
        ], true) && filled($this->translated_text);
    }

    public static function hashSource(string $sourceText): string
    {
        return hash('sha256', $sourceText);
    }
}
