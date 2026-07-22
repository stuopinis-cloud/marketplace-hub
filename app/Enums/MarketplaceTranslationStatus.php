<?php

namespace App\Enums;

enum MarketplaceTranslationStatus: string
{
    case Missing = 'missing';
    case Queued = 'queued';
    case AutoTranslated = 'auto_translated';
    case Reviewed = 'reviewed';
    case Approved = 'approved';
    case Failed = 'failed';

    /**
     * @return list<string>
     */
    public static function usableValues(): array
    {
        return [
            self::Approved->value,
            self::Reviewed->value,
            self::AutoTranslated->value,
        ];
    }
}
