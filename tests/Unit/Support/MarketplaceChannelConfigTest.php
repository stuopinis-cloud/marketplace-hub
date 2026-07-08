<?php

namespace Tests\Unit\Support;

use App\Support\MarketplaceChannelConfig;
use Tests\TestCase;

class MarketplaceChannelConfigTest extends TestCase
{
    public function test_bool_parses_common_truthy_and_falsy_values(): void
    {
        $config = MarketplaceChannelConfig::for([
            'true_string' => 'true',
            'one_string' => '1',
            'yes_string' => 'yes',
            'false_string' => 'false',
            'zero_string' => '0',
            'empty_string' => '',
            'null_value' => null,
            'actual_bool' => true,
        ]);

        $this->assertTrue($config->bool('true_string'));
        $this->assertTrue($config->bool('one_string'));
        $this->assertTrue($config->bool('yes_string'));
        $this->assertFalse($config->bool('false_string'));
        $this->assertFalse($config->bool('zero_string'));
        $this->assertFalse($config->bool('empty_string'));
        $this->assertFalse($config->bool('null_value', false));
        $this->assertTrue($config->bool('actual_bool'));
        $this->assertTrue($config->bool('missing_key', true));
    }
}
