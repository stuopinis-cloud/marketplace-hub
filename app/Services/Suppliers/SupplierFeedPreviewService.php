<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use App\Services\Suppliers\Csv\SupplierCsvParser;
use App\Services\Suppliers\Csv\SupplierCsvSupplierImporter;
use App\Services\Suppliers\Json\SupplierJsonSupplierImporter;
use App\Services\Suppliers\Xml\SupplierXmlConfig;
use App\Services\Suppliers\Xml\SupplierXmlFeedClient;
use App\Services\Suppliers\Xml\SupplierXmlParser;
use Throwable;

class SupplierFeedPreviewService
{
    public function __construct(
        private readonly SupplierCsvSupplierImporter $csvImporter,
        private readonly SupplierJsonSupplierImporter $jsonImporter,
        private readonly SupplierXmlFeedClient $xmlFeedClient,
        private readonly SupplierXmlParser $xmlParser,
        private readonly SupplierCsvParser $csvParser,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(Supplier $supplier): array
    {
        try {
            return match ($supplier->connector_type) {
                Supplier::CONNECTOR_CSV_URL, Supplier::CONNECTOR_CSV_UPLOAD => $this->previewCsv($supplier),
                Supplier::CONNECTOR_XML_URL, Supplier::CONNECTOR_MTAC => $this->previewXml($supplier),
                Supplier::CONNECTOR_JSON_API, Supplier::CONNECTOR_API, Supplier::CONNECTOR_HELIK_API => $this->previewJson($supplier),
                default => [
                    'error' => 'Preview is not available for this connector type.',
                    'type' => $supplier->connector_type,
                ],
            };
        } catch (Throwable $exception) {
            return [
                'error' => $this->friendlyError($exception),
                'type' => $supplier->connector_type,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function previewCsv(Supplier $supplier): array
    {
        $preview = $this->csvImporter->preview($supplier, 10);
        $content = app(Csv\SupplierCsvFeedClient::class)->fetch($supplier);
        $parsed = $this->csvParser->parse($content, $supplier, 10);

        return [
            'error' => null,
            'type' => 'csv',
            'detected_delimiter' => $parsed['detected_delimiter'] ?? null,
            'headers' => $preview['headers'],
            'preview_rows' => array_slice($preview['preview_rows'], 0, 10),
            'sample_mappings' => $preview['sample_mappings'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function previewXml(Supplier $supplier): array
    {
        if (blank($supplier->endpoint_url)) {
            return ['error' => 'Endpoint URL is required for XML preview.', 'type' => 'xml'];
        }

        $xml = $this->xmlFeedClient->fetch((string) $supplier->endpoint_url);

        if (! SupplierXmlConfig::isConfigured($supplier) && $supplier->connector_type === Supplier::CONNECTOR_MTAC) {
            return [
                'error' => null,
                'type' => 'xml',
                'message' => 'M-Tac uses built-in Google Atom paths. Save mappings only if you need custom XPath.',
                'sample_length' => strlen($xml),
            ];
        }

        $parsed = $this->xmlParser->parse($xml, $supplier);

        return [
            'error' => null,
            'type' => 'xml',
            'item_count' => count($parsed['entries']) + count($parsed['skipped']),
            'entry_count' => count($parsed['entries']),
            'sample_entries' => array_slice($parsed['entries'], 0, 10),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function previewJson(Supplier $supplier): array
    {
        if ($supplier->connector_type === Supplier::CONNECTOR_HELIK_API
            || ($supplier->connector_type === Supplier::CONNECTOR_API && $supplier->code === Supplier::CODE_HELIK)) {
            // Use Helik client for built-in adapter preview sample
            $body = app(Helik\HelikFeedClient::class)->fetch($supplier);
            $decoded = json_decode($body, true);

            return [
                'error' => null,
                'type' => 'json',
                'top_level_keys' => is_array($decoded) ? array_keys($decoded) : [],
                'sample_rows' => is_array(data_get($decoded, 'Value'))
                    ? array_slice(data_get($decoded, 'Value'), 0, 10)
                    : [],
                'entry_count' => is_array(data_get($decoded, 'Value')) ? count(data_get($decoded, 'Value')) : 0,
            ];
        }

        $preview = $this->jsonImporter->preview($supplier, 10);

        return [
            'error' => null,
            'type' => 'json',
            ...$preview,
        ];
    }

    private function friendlyError(Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        if ($message === '') {
            return 'Feed preview failed. Check connection settings and try again.';
        }

        return $message;
    }
}
