<?php

namespace App\Console\Commands;

use App\Enums\SyncJobStatus;
use App\Models\SyncJob;
use App\Services\Sync\SyncJobHealthService;
use Illuminate\Console\Command;

class SyncStatusCommand extends Command
{
    protected $signature = 'sync:status
                            {--source= : Filter by sync source, e.g. shopify}
                            {--latest : Show only the latest matching job}
                            {--running : Show only running jobs}
                            {--json : Output JSON}';

    protected $description = 'Show sync job health and progress details';

    public function handle(SyncJobHealthService $healthService): int
    {
        $jobs = $this->resolveJobs();

        if ($jobs->isEmpty()) {
            $this->components->warn('No matching sync jobs found.');

            return self::SUCCESS;
        }

        if ((bool) $this->option('json')) {
            $payload = $jobs->map(function (SyncJob $job) use ($healthService): array {
                return $this->serializeJob($job, $healthService);
            })->values()->all();

            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        foreach ($jobs as $job) {
            $this->renderJob($job, $healthService);

            if ($jobs->count() > 1) {
                $this->newLine();
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, SyncJob>
     */
    private function resolveJobs()
    {
        $query = SyncJob::query()->orderByDesc('id');

        if (filled($this->option('source'))) {
            $query->where('source', (string) $this->option('source'));
        }

        if ((bool) $this->option('running')) {
            $query->where('status', SyncJobStatus::Running);
        }

        if ((bool) $this->option('latest')) {
            $job = $query->first();

            return $job ? collect([$job]) : collect();
        }

        return $query->limit(20)->get();
    }

    private function renderJob(SyncJob $job, SyncJobHealthService $healthService): void
    {
        $health = $healthService->assess($job);

        $this->line('ID: '.$job->id);
        $this->line('Source: '.($job->source ?? '—'));
        $this->line('Status: '.($job->status?->value ?? '—'));
        $this->line('Health: '.$health['health_status']);
        $this->line('Progress: '.$health['progress_label']);
        $this->line('Started: '.($job->started_at?->toDateTimeString() ?? '—'));
        $this->line('Finished: '.($job->finished_at?->toDateTimeString() ?? '—'));
        $this->line('Last heartbeat: '.($job->heartbeat_at?->toDateTimeString() ?? '—'));
        $this->line('Heartbeat age: '.$healthService->heartbeatAgeLabel($job));
        $this->line('Current product: '.($health['current_product_handle'] ?? '—'));
        $this->line('Stage: '.($health['stage'] ?? '—'));
        $this->line('Success items: '.$job->success_items);
        $this->line('Failed items: '.$job->failed_items);
        $this->line('Process ID: '.($job->process_id ?? '—'));
        $this->line('Message: '.$health['human_message']);

        if (filled($job->error_message)) {
            $this->line('Error: '.$job->error_message);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeJob(SyncJob $job, SyncJobHealthService $healthService): array
    {
        $health = $healthService->assess($job);

        return [
            'id' => $job->id,
            'type' => $job->type,
            'source' => $job->source,
            'status' => $job->status?->value,
            'health' => $health,
            'started_at' => $job->started_at?->toIso8601String(),
            'finished_at' => $job->finished_at?->toIso8601String(),
            'heartbeat_at' => $job->heartbeat_at?->toIso8601String(),
            'error_message' => $job->error_message,
            'total_items' => $job->total_items,
            'success_items' => $job->success_items,
            'failed_items' => $job->failed_items,
            'process_id' => $job->process_id,
            'context' => $job->context,
        ];
    }
}
