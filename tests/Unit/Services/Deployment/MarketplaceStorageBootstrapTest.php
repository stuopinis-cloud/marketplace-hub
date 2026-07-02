<?php

namespace Tests\Unit\Services\Deployment;

use App\Services\Deployment\MarketplaceStorageBootstrap;
use Tests\TestCase;

class MarketplaceStorageBootstrapTest extends TestCase
{
    public function test_required_directories_are_created(): void
    {
        $bootstrap = new MarketplaceStorageBootstrap;

        $bootstrap->ensureDirectoriesExist();

        foreach ($bootstrap->requiredDirectories() as $directory) {
            $this->assertDirectoryExists($directory);
        }
    }
}
