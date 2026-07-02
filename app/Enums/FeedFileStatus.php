<?php

namespace App\Enums;

enum FeedFileStatus: string
{
    case Generated = 'generated';
    case Published = 'published';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Generated => 'Generated',
            self::Published => 'Published',
            self::Failed => 'Failed',
        };
    }
}
