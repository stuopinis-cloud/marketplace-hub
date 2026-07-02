<?php

namespace App\Services\Deployment;

use Illuminate\Support\Facades\File;

class MarketplaceStorageBootstrap
{
    /**
     * @return array<int, string>
     */
    public function requiredDirectories(): array
    {
        return [
            storage_path('app/public/feeds'),
            storage_path('app/public/exports'),
        ];
    }

    public function ensureDirectoriesExist(): void
    {
        foreach ($this->requiredDirectories() as $directory) {
            File::ensureDirectoryExists($directory, 0755);
        }
    }
}
