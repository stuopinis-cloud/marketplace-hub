<?php

namespace App\Console\Commands;

use App\Exceptions\Shopify\ShopifyConfigurationException;
use App\Exceptions\Shopify\ShopifyTokenException;
use App\Services\Shopify\ShopifyClient;
use Illuminate\Console\Command;
use Throwable;

class ShopifyTestConnectionCommand extends Command
{
    protected $signature = 'shopify:test-connection';

    protected $description = 'Test the Shopify Admin API connection';

    private const SHOP_QUERY = <<<'GRAPHQL'
    {
      shop {
        name
        myshopifyDomain
        primaryDomain {
          url
        }
      }
    }
    GRAPHQL;

    public function handle(ShopifyClient $client): int
    {
        if (! $client->isConfigured()) {
            $this->components->error(ShopifyConfigurationException::missingCredentials()->getMessage());

            return self::FAILURE;
        }

        try {
            $client->getAccessToken();

            $response = $client->query(self::SHOP_QUERY);
            $shop = $response['data']['shop'] ?? null;

            if (! is_array($shop)) {
                $this->components->error('Shopify connection succeeded but the shop payload was missing from the response.');

                return self::FAILURE;
            }

            $this->components->info('Shopify connection successful.');

            $this->line('Shop name: '.($shop['name'] ?? 'n/a'));
            $this->line('myshopify domain: '.($shop['myshopifyDomain'] ?? 'n/a'));

            $primaryDomainUrl = $shop['primaryDomain']['url'] ?? null;

            if (filled($primaryDomainUrl)) {
                $this->line('Primary domain: '.$primaryDomainUrl);
            } else {
                $this->line('Primary domain: not available');
            }

            return self::SUCCESS;
        } catch (ShopifyConfigurationException|ShopifyTokenException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->components->error('Shopify connection failed: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
