<?php

namespace Tests\Unit\Services\Marketplace\Varle;

use App\Services\Marketplace\Varle\VarleXmlFeedValidator;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VarleXmlFeedValidatorTest extends TestCase
{
    public function test_valid_feed_passes_validation(): void
    {
        Storage::fake('local');
        $path = Storage::disk('local')->path('valid.xml');
        Storage::disk('local')->put('valid.xml', '<?xml version="1.0" encoding="UTF-8"?><products><product><quantity>1</quantity></product></products>');

        $result = app(VarleXmlFeedValidator::class)->validate($path);

        $this->assertTrue($result->valid);
    }

    public function test_zero_quantity_fails_validation(): void
    {
        Storage::fake('local');
        $path = Storage::disk('local')->path('invalid.xml');
        Storage::disk('local')->put('invalid.xml', '<?xml version="1.0" encoding="UTF-8"?><products><product><quantity>0</quantity></product></products>');

        $result = app(VarleXmlFeedValidator::class)->validate($path);

        $this->assertFalse($result->valid);
        $this->assertStringContainsString('non-positive', $result->message());
    }

    public function test_malformed_xml_fails_validation(): void
    {
        Storage::fake('local');
        $path = Storage::disk('local')->path('broken.xml');
        Storage::disk('local')->put('broken.xml', '<products><product></products>');

        $result = app(VarleXmlFeedValidator::class)->validate($path);

        $this->assertFalse($result->valid);
        $this->assertStringContainsString('well-formed', $result->message());
    }
}
