<?php

namespace App\Enums;

enum MarketplaceListingStatus: string
{
    case Pending = 'pending';
    case Exported = 'exported';
    case Failed = 'failed';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Exported => 'Exported',
            self::Failed => 'Failed',
            self::Disabled => 'Disabled',
        };
    }
}
