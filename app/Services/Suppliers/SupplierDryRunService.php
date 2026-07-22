<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Suppliers\Csv\SupplierCsvConfig;
use App\Services\Suppliers\Csv\SupplierCsvFeedClient;
use App\Services\Suppliers\Csv\SupplierCsvParser;
use App\Services\Suppliers\Helik\HelikFeedClient;
use App\Services\Suppliers\Helik\HelikResponseParser;
use App\Services\Suppliers\Helik\HelikSupplierImporter;
use App\Services\Suppliers\Json\SupplierJsonFeedClient;
use App\Services\Suppliers\Json\SupplierJsonParser;
use App\Services\Suppliers\Mtac\MtacFeedClient;
use App\Services\Suppliers\Mtac\MtacSkuMatcher;
use App\Services\Suppliers\Mtac\MtacXmlParser;
use App\Services\Suppliers\Xml\SupplierXmlConfig;
use App\Services\Suppliers\Xml\SupplierXmlFeedClient;
use App\Services\Suppliers\Xml\SupplierXmlParser;
use Throwable;

class SupplierDryRunService
{
    public function __construct(
        private readonly SupplierOnboardingValidator $validator,
        private readonly SupplierCsvFeedClient $csvFeedClient,
        private readonly SupplierCsvParser $csvParser,
        private readonly SupplierXmlFeedClient $xmlFeedClient,
        private readonly SupplierXmlParser $xmlParser,
        private readonly SupplierJsonFeedClient $jsonFeedClient,
        private readonly SupplierJsonParser $jsonParser,
        private readonly MtacFeedClient $mtacFeedClient,
        private readonly MtacXmlParser $mtacXmlParser,
        private readonly HelikFeedClient $helikFeedClient,
        private readonly HelikResponseParser $helikResponseParser,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(Supplier $supplier): array
    {
        $errors = $this->validator->validateForSync($supplier);

        if ($errors !== []) {
            return [
                'error' => implode(' ', $errors),
                'stats' => null,
                'matched_examples' => [],
                'unmatched_examples' => [],
                'ambiguous_examples' => [],
            ];
        }

        $beforeCount = SupplierProduct::query()->where('supplier_id', $supplier->id)->count();

        try {
            [$entries, $skipped] = $this->parseEntries($supplier);
        } catch (Throwable $exception) {
            return [
                'error' => $exception->getMessage(),
                'stats' => null,
                'matched_examples' => [],
                'unmatched_examples' => [],
                'ambiguous_examples' => [],
            ];
        }

        $vendorScope = $this->vendorScopeFor($supplier);
        $matcher = SupplierSkuMatcher::forSupplier($supplier, $vendorScope);
        $shopifyVariants = $matcher->loadShopifyVariants();
        $existingMappings = SupplierProduct::query()
            ->where('supplier_id', $supplier->id)
            ->with('productVariant')
            ->get();

        $matchedExamples = [];
        $unmatchedExamples = [];
        $ambiguousExamples = [];
        $matched = 0;
        $unmatched = 0;
        $ambiguous = 0;
        $missingQuantity = 0;

        foreach ($entries as $entry) {
            if (($entry['parse_issue_code'] ?? null) === 'missing_quantity') {
                $missingQuantity++;
            }

            $match = $matcher->match(
                $entry['sku'],
                $shopifyVariants,
                $existingMappings,
                isset($entry['raw_payload']['barcode']) ? (string) $entry['raw_payload']['barcode'] : null,
            );

            $sample = [
                'sku' => $entry['sku'],
                'stock_quantity' => $entry['stock_quantity'] ?? null,
                'match_status' => $match['match_status'],
                'match_method' => $match['match_method'],
                'issue_code' => $match['issue_code'],
            ];

            if ($match['issue_code'] === 'duplicate_shopify_sku' || $match['match_status'] === SupplierProduct::MATCH_STATUS_AMBIGUOUS) {
                $ambiguous++;
                if (count($ambiguousExamples) < 20) {
                    $ambiguousExamples[] = $sample;
                }
            } elseif ($match['match_status'] === SupplierProduct::MATCH_STATUS_MATCHED) {
                $matched++;
                if (count($matchedExamples) < 20) {
                    $matchedExamples[] = $sample;
                }
            } else {
                $unmatched++;
                if (count($unmatchedExamples) < 20) {
                    $unmatchedExamples[] = $sample;
                }
            }
        }

        $missingSku = count(array_filter(
            $skipped,
            fn (array $row): bool => ($row['issue_code'] ?? null) === 'missing_sku',
        ));

        $afterCount = SupplierProduct::query()->where('supplier_id', $supplier->id)->count();

        return [
            'error' => null,
            'mutated' => $afterCount !== $beforeCount,
            'stats' => [
                'parsed' => count($entries),
                'valid_rows' => count($entries),
                'missing_sku' => $missingSku,
                'missing_quantity' => $missingQuantity,
                'matched' => $matched,
                'unmatched' => $unmatched,
                'ambiguous' => $ambiguous,
                'skipped' => count($skipped),
            ],
            'matched_examples' => $matchedExamples,
            'unmatched_examples' => $unmatchedExamples,
            'ambiguous_examples' => $ambiguousExamples,
        ];
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function parseEntries(Supplier $supplier): array
    {
        return match ($supplier->connector_type) {
            Supplier::CONNECTOR_CSV_URL, Supplier::CONNECTOR_CSV_UPLOAD => $this->parseCsv($supplier),
            Supplier::CONNECTOR_XML_URL => $this->parseXml($supplier),
            Supplier::CONNECTOR_MTAC => $this->parseMtac($supplier),
            Supplier::CONNECTOR_JSON_API => $this->parseJson($supplier),
            Supplier::CONNECTOR_HELIK_API, Supplier::CONNECTOR_API => $this->parseHelikOrJson($supplier),
            default => throw new \InvalidArgumentException('Unsupported connector for dry run.'),
        };
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function parseCsv(Supplier $supplier): array
    {
        $parsed = $this->csvParser->parse($this->csvFeedClient->fetch($supplier), $supplier);

        return [$parsed['entries'], $parsed['skipped']];
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function parseXml(Supplier $supplier): array
    {
        if (! SupplierXmlConfig::isConfigured($supplier)) {
            throw new \RuntimeException('XML item path and SKU path mapping are required.');
        }

        $parsed = $this->xmlParser->parse(
            $this->xmlFeedClient->fetch((string) $supplier->endpoint_url),
            $supplier,
        );

        return [$parsed['entries'], $parsed['skipped']];
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function parseMtac(Supplier $supplier): array
    {
        $entries = $this->mtacXmlParser->parse(
            $this->mtacFeedClient->fetch((string) $supplier->endpoint_url),
        );

        return [$entries, []];
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function parseJson(Supplier $supplier): array
    {
        $parsed = $this->jsonParser->parse($this->jsonFeedClient->fetch($supplier), $supplier);

        return [$parsed['entries'], $parsed['skipped']];
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function parseHelikOrJson(Supplier $supplier): array
    {
        if ($supplier->connector_type === Supplier::CONNECTOR_HELIK_API
            || $supplier->code === Supplier::CODE_HELIK) {
            $parsed = $this->helikResponseParser->parse(
                $this->helikFeedClient->fetch($supplier),
                (string) data_get($supplier->config, 'response_data_path', 'Value'),
            );

            return [$parsed['entries'], $parsed['skipped']];
        }

        return $this->parseJson($supplier);
    }

    /**
     * @return array<int, string>
     */
    private function vendorScopeFor(Supplier $supplier): array
    {
        return match ($supplier->connector_type) {
            Supplier::CONNECTOR_MTAC => [MtacSkuMatcher::VENDOR],
            Supplier::CONNECTOR_HELIK_API => HelikSupplierImporter::VENDOR_SCOPE,
            Supplier::CONNECTOR_API => $supplier->code === Supplier::CODE_HELIK
                ? HelikSupplierImporter::VENDOR_SCOPE
                : SupplierCsvConfig::vendorScope($supplier),
            default => SupplierCsvConfig::vendorScope($supplier),
        };
    }
}
