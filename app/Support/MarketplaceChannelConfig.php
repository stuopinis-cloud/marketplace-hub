<?php

namespace App\Support;

use App\Models\MarketplaceChannel;

class MarketplaceChannelConfig
{
    /**
     * @param  array<string, mixed>|MarketplaceChannel  $channel
     */
    public function __construct(
        private readonly array|MarketplaceChannel $channel,
    ) {}

    /**
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    public static function merge(array $config, array $defaults): array
    {
        return array_merge($defaults, $config);
    }

    /**
     * @param  array<string, mixed>|MarketplaceChannel  $channel
     */
    public static function for(array|MarketplaceChannel $channel): self
    {
        return new self($channel);
    }

    public function string(string $key, ?string $default = null): ?string
    {
        $value = $this->value($key);

        if ($value === null) {
            return $default;
        }

        $string = trim((string) $value);

        return $string === '' ? $default : $string;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->value($key);

        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off', '' => false,
            default => $default,
        };
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->value($key);

        if ($value === null || $value === '') {
            return $default;
        }

        return (int) $value;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->value($key);

        if ($value === null || $value === '') {
            return $default;
        }

        return (float) $value;
    }

  /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->channel instanceof MarketplaceChannel) {
            return $this->channel->config ?? [];
        }

        return $this->channel;
    }

    private function value(string $key): mixed
    {
        return data_get($this->all(), $key);
    }
}
