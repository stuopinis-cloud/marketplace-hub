<?php

namespace Tests\Unit\Services\Marketplace\Varle;

use App\Enums\SyncJobStatus;
use App\Jobs\RefreshVarleReadinessJob;
use App\Models\Product;
use App\Models\Source;
use App\Services\Marketplace\Varle\VarleReadinessRefreshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\Support\VarleCatalogFixtures;
use Tests\TestCase;

class VarleReadinessRefreshServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_dispatch_queues_job_and_creates_sync_job(): void
    {
        Bus::fake();

        $result = app(VarleReadinessRefreshService::class)->dispatch(chunkSize: 50);

        $this->assertTrue($result->dispatched);
        $this->assertNotNull($result->syncJob);
        $this->assertSame(SyncJobStatus::Pending, $result->syncJob->status);
        $this->assertSame(0, $result->syncJob->total_items);

        Bus::assertDispatched(RefreshVarleReadinessJob::class, function (RefreshVarleReadinessJob $job) use ($result): bool {
            return $job->syncJobId === $result->syncJob?->id
                && $job->chunkSize === 50;
        });
    }

    public function test_dispatch_prevents_duplicate_refresh(): void
    {
        Bus::fake();

        $service = app(VarleReadinessRefreshService::class);
        $first = $service->dispatch();
        $second = $service->dispatch();

        $this->assertTrue($first->dispatched);
        $this->assertTrue($second->alreadyRunning);

        Bus::assertDispatchedTimes(RefreshVarleReadinessJob::class, 1);
    }

    public function test_run_processes_products_in_chunks_and_updates_progress(): void
    {
        VarleCatalogFixtures::createExportableVariant(productOverrides: ['handle' => 'ready-1']);
        VarleCatalogFixtures::createExportableVariant(productOverrides: ['handle' => 'ready-2']);

        $service = app(VarleReadinessRefreshService::class);
        $syncJob = \App\Models\SyncJob::query()->create([
            'type' => 'readiness',
            'source' => 'marketplace',
            'channel' => 'varle',
            'status' => SyncJobStatus::Pending,
            'total_items' => 2,
            'context' => ['chunk_size' => 1],
        ]);

        $service->run($syncJob->id, chunkSize: 1);

        $syncJob->refresh();

        $this->assertSame(SyncJobStatus::Completed, $syncJob->status);
        $this->assertSame(2, $syncJob->success_items);
        $this->assertSame(0, $syncJob->failed_items);
        $this->assertNotNull($syncJob->finished_at);
        $this->assertSame('completed', data_get($syncJob->context, 'stage'));
        $this->assertSame(2, data_get($syncJob->context, 'current_product_index'));

        $this->assertSame(2, Product::query()->whereNotNull('varle_readiness_cached_at')->count());
    }

    public function test_run_marks_failed_when_lock_is_held(): void
    {
        $lock = Cache::lock(VarleReadinessRefreshService::LOCK_KEY, 60);
        $lock->get();

        try {
            $service = app(VarleReadinessRefreshService::class);
            $syncJob = \App\Models\SyncJob::query()->create([
                'type' => 'readiness',
                'source' => 'marketplace',
                'channel' => 'varle',
                'status' => SyncJobStatus::Pending,
                'total_items' => 0,
            ]);

            $service->run($syncJob->id);

            $syncJob->refresh();
            $this->assertSame(SyncJobStatus::Failed, $syncJob->status);
            $this->assertStringContainsString('already running', (string) $syncJob->error_message);
        } finally {
            $lock->release();
        }
    }

    public function test_run_synchronously_completes_without_queue(): void
    {
        VarleCatalogFixtures::createExportableVariant();

        $count = app(VarleReadinessRefreshService::class)->runSynchronously(chunkSize: 100);

        $this->assertSame(1, $count);
        $this->assertSame(1, Product::query()->whereNotNull('varle_readiness_cached_at')->count());
    }
}
