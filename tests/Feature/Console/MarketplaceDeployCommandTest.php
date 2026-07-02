<?php

namespace Tests\Feature\Console;

use Tests\TestCase;

class MarketplaceDeployCommandTest extends TestCase
{
    public function test_deploy_command_prints_dry_run_checklist(): void
    {
        $this->artisan('marketplace:deploy --dry-run')
            ->expectsOutputToContain('composer install --no-dev --optimize-autoloader')
            ->expectsOutputToContain('php artisan migrate --force')
            ->expectsOutputToContain('php artisan storage:link')
            ->expectsOutputToContain('php artisan optimize:clear')
            ->expectsOutputToContain('php artisan config:cache')
            ->expectsOutputToContain('php artisan route:cache')
            ->expectsOutputToContain('php artisan view:cache')
            ->assertSuccessful();
    }
}
