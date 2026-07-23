<?php

namespace Tests\Feature;

use App\Services\Marketplace\Varle\VarleXmlExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\VarleCatalogFixtures;
use Tests\TestCase;

class PublicFeedControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_feed_route_returns_xml(): void
    {
        Storage::fake('public');

        VarleCatalogFixtures::createExportableVariant();
        $this->app->make(VarleXmlExporter::class)->export();

        $response = $this->get('/feeds/varle.xml');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $response->assertSee('<products>', false);
        $response->assertSee('<product>', false);
    }

    public function test_public_feed_route_returns_not_found_when_feed_missing(): void
    {
        Storage::fake('public');

        $this->get('/feeds/varle.xml')->assertNotFound();
    }

    public function test_ebay_public_feed_route_returns_xml(): void
    {
        Storage::disk('public')->put('feeds/ebay-en.xml', '<?xml version="1.0"?><ebayFeed/>');

        $response = $this->get('/feeds/ebay-en.xml');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $response->assertSee('<ebayFeed', false);
    }
}
