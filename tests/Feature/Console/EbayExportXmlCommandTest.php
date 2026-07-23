<?php

namespace Tests\Feature\Console;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EbayExportXmlCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_ebay_export_xml_writes_to_public_feeds_path(): void
    {
        $source = Source::query()->firstOrCreate(
            ['type' => 'shopify'],
            ['name' => 'Shopify', 'enabled' => true, 'config' => []],
        );

        Product::query()->create([
            'source_id' => $source->id,
            'external_id' => 'ebay-export-1',
            'title' => 'Exportable Product',
            'vendor' => 'Vendor',
            'status' => ProductStatus::Active,
            'imported_at' => now(),
        ]);

        $publicRelative = 'feeds/ebay-en.xml';
        $publicAbsolute = storage_path('app/public/'.$publicRelative);
        $privateAbsolute = storage_path('app/private/'.$publicRelative);

        foreach ([$publicAbsolute, $privateAbsolute] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $exitCode = Artisan::call('ebay:export-xml', ['--locale' => 'en']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('Public path:', $output);
        $this->assertStringContainsString('app/public/feeds/ebay-en.xml', $output);
        $this->assertStringContainsString('Public URL:', $output);
        $this->assertStringContainsString('/feeds/ebay-en.xml', $output);

        $this->assertTrue(Storage::disk('public')->exists($publicRelative), $output);
        $this->assertFileExists($publicAbsolute);
        $this->assertFileDoesNotExist($privateAbsolute);
        $this->assertStringContainsString('<ebayFeed>', (string) file_get_contents($publicAbsolute));
    }
}
