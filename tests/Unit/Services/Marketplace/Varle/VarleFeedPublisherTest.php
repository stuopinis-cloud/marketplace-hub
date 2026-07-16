<?php

namespace Tests\Unit\Services\Marketplace\Varle;

use App\Models\MarketplaceChannel;
use App\Services\Marketplace\Varle\VarleExportResult;
use App\Services\Marketplace\Varle\VarleFeedPublisher;
use App\Services\Marketplace\Varle\VarleXmlExporter;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;

class VarleFeedPublisherTest extends TestCase
{
    public function test_invalid_temp_feed_keeps_existing_public_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('feeds/varle.xml', '<?xml version="1.0"?><products><product><quantity>2</quantity></product></products>');

        $this->mock(VarleXmlExporter::class, function (MockInterface $mock): void {
            $channel = MarketplaceChannel::make([
                'name' => 'Varle.lt',
                'type' => 'varle',
                'enabled' => true,
                'config' => [],
            ]);

            $mock->shouldReceive('resolveChannelForPublishing')->once()->andReturn($channel);
            $mock->shouldReceive('channelConfigForPublishing')->once()->andReturn([]);
            $mock->shouldReceive('feedRelativePathForPublishing')->once()->andReturn('feeds/varle.xml');
            $mock->shouldReceive('export')
                ->once()
                ->with(false, 'feeds/varle.xml.tmp', false)
                ->andReturnUsing(function (): VarleExportResult {
                    Storage::disk('public')->put(
                        'feeds/varle.xml.tmp',
                        '<?xml version="1.0"?><products><product><quantity>0</quantity></product></products>',
                    );

                    return new VarleExportResult(
                        9,
                        1,
                        0,
                        Storage::disk('public')->path('feeds/varle.xml.tmp'),
                        'http://example.test/feeds/varle.xml.tmp',
                    );
                });
            $mock->shouldReceive('updateSyncJobStageById')->once()->with(9, 'validating');
            $mock->shouldReceive('markExportPublicationFailed')->once();
            $mock->shouldReceive('isSyncJobFinalized')->andReturn(false);
        });

        try {
            app(VarleFeedPublisher::class)->publish();
            $this->fail('Expected publication to fail validation.');
        } catch (\RuntimeException) {
        }

        $this->assertTrue(Storage::disk('public')->exists('feeds/varle.xml'));
        $this->assertFalse(Storage::disk('public')->exists('feeds/varle.xml.tmp'));
        $this->assertStringContainsString('<quantity>2</quantity>', Storage::disk('public')->get('feeds/varle.xml'));
    }

    public function test_valid_temp_feed_replaces_public_file_atomically(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('feeds/varle.xml', '<?xml version="1.0"?><products><product><quantity>2</quantity></product></products>');

        $this->mock(VarleXmlExporter::class, function (MockInterface $mock): void {
            $channel = MarketplaceChannel::make([
                'name' => 'Varle.lt',
                'type' => 'varle',
                'enabled' => true,
                'config' => [],
            ]);

            $mock->shouldReceive('resolveChannelForPublishing')->once()->andReturn($channel);
            $mock->shouldReceive('channelConfigForPublishing')->once()->andReturn([]);
            $mock->shouldReceive('feedRelativePathForPublishing')->once()->andReturn('feeds/varle.xml');
            $mock->shouldReceive('export')
                ->once()
                ->with(false, 'feeds/varle.xml.tmp', false)
                ->andReturnUsing(function (): VarleExportResult {
                    Storage::disk('public')->put(
                        'feeds/varle.xml.tmp',
                        '<?xml version="1.0" encoding="UTF-8"?><products><product><quantity>5</quantity></product></products>',
                    );

                    return new VarleExportResult(
                        10,
                        1,
                        0,
                        Storage::disk('public')->path('feeds/varle.xml.tmp'),
                        'http://example.test/feeds/varle.xml.tmp',
                    );
                });
            $mock->shouldReceive('updateSyncJobStageById')->once()->with(10, 'validating');
            $mock->shouldReceive('updateSyncJobStageById')->once()->with(10, 'publishing');
            $mock->shouldReceive('registerPublishedFeed')->once();
            $mock->shouldReceive('isSyncJobFinalized')->andReturn(true);
        });

        $result = app(VarleFeedPublisher::class)->publish();

        $this->assertSame(10, $result->syncJobId);
        $this->assertTrue(Storage::disk('public')->exists('feeds/varle.xml'));
        $this->assertFalse(Storage::disk('public')->exists('feeds/varle.xml.tmp'));
        $this->assertStringContainsString('<quantity>5</quantity>', Storage::disk('public')->get('feeds/varle.xml'));
    }
}
