<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class MarketplaceDeployCommand extends Command
{
    protected $signature = 'marketplace:deploy
                            {--dry-run : Print deployment steps without executing them}
                            {--with-composer : Also run composer install --no-dev --optimize-autoloader}';

    protected $description = 'Run the production deployment checklist for Marketplace Hub';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $withComposer = (bool) $this->option('with-composer');

        $this->components->info('Marketplace Hub production deployment checklist');
        $this->newLine();

        $steps = [
            'composer install --no-dev --optimize-autoloader' => function () use ($withComposer, $dryRun): void {
                if (! $withComposer) {
                    $this->components->warn('Skipped composer install. Re-run with --with-composer or run it manually before deploy.');

                    return;
                }

                if ($dryRun) {
                    return;
                }

                $exitCode = 0;
                passthru('composer install --no-dev --optimize-autoloader', $exitCode);

                if ($exitCode !== 0) {
                    throw new \RuntimeException('composer install failed.');
                }
            },
            'php artisan migrate --force' => fn () => Artisan::call('migrate', ['--force' => true], $this->output),
            'php artisan storage:link' => function (): void {
                if (File::exists(public_path('storage'))) {
                    $this->components->warn('public/storage already exists, skipping storage:link.');

                    return;
                }

                Artisan::call('storage:link', [], $this->output);
            },
            'php artisan optimize:clear' => fn () => Artisan::call('optimize:clear', [], $this->output),
            'php artisan config:cache' => fn () => Artisan::call('config:cache', [], $this->output),
            'php artisan route:cache' => fn () => Artisan::call('route:cache', [], $this->output),
            'php artisan view:cache' => fn () => Artisan::call('view:cache', [], $this->output),
        ];

        foreach ($steps as $label => $callback) {
            $this->line($dryRun ? "[dry-run] {$label}" : "→ {$label}");

            if ($dryRun) {
                continue;
            }

            $callback();
        }

        $this->newLine();

        if ($dryRun) {
            $this->components->info('Dry run complete. Execute without --dry-run to apply Laravel deployment steps.');
        } else {
            $this->components->info('Deployment steps completed.');
            $this->line('Next: php artisan marketplace:health-check');
        }

        return self::SUCCESS;
    }
}
