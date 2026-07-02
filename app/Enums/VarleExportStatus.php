<?php

namespace App\Enums;

enum VarleExportStatus: string
{
    case PendingReview = 'pending_review';
    case Auto = 'auto';
    case Include = 'include';
    case Exclude = 'exclude';

    public function label(): string
    {
        return match ($this) {
            self::PendingReview => 'Pending review',
            self::Auto => 'Auto',
            self::Include => 'Include',
            self::Exclude => 'Exclude',
        };
    }
}
