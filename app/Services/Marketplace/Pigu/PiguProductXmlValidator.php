<?php

namespace App\Services\Marketplace\Pigu;

use App\Models\PiguCategoryField;
use Illuminate\Support\Facades\Storage;
use SimpleXMLElement;

class PiguProductXmlValidator
{
    public function __construct(
        private readonly PiguProductFeedExporter $products,
        private readonly PiguImageUrlNormalizer $images = new PiguImageUrlNormalizer,
    ) {}

    /**
     * @param  list<string>|null  $easyEligibleCategoryIds
     * @return array{
     *     valid: bool,
     *     products_checked: int,
     *     modifications_checked: int,
     *     categories_count: int,
     *     errors_by_type: array<string, int>,
     *     errors: list<array{type: string, message: string, product_title?: string}>,
     *     warnings: list<string>
     * }
     */
    public function validateFeed(?string $relativePath = null, ?array $easyEligibleCategoryIds = null): array
    {
        $config = config('marketplace.exports.pigu', []);
        $relativePath ??= (string) ($config['feed_path'] ?? 'feeds/pigu-products.xml');

        $errors = [];
        $errorsByType = [];
        $warnings = [
            'Pigu product XML does not include prices. Confirm separate offer/price/stock feed requirements before going live.',
        ];

        if (! Storage::disk('public')->exists($relativePath)) {
            $this->pushError($errors, $errorsByType, 'feed_missing', "Feed file missing: {$relativePath}");

            return $this->result(false, 0, 0, 0, $errorsByType, $errors, $warnings);
        }

        $xmlString = Storage::disk('public')->get($relativePath);
        $xml = @simplexml_load_string((string) $xmlString);

        if ($xml === false) {
            $this->pushError($errors, $errorsByType, 'malformed_xml', 'Feed XML is not well-formed.');

            return $this->result(false, 0, 0, 0, $errorsByType, $errors, $warnings);
        }

        $productsChecked = 0;
        $modificationsChecked = 0;
        $categoryIds = [];

        foreach ($xml->product ?? [] as $product) {
            $productsChecked++;
            $title = trim((string) ($product->title ?? ''));
            $categoryId = trim((string) ($product->{'category-id'} ?? ''));
            $categoryName = trim((string) ($product->{'category-name'} ?? ''));
            $description = trim((string) ($product->{'long-description'} ?? ''));

            if ($categoryId === '') {
                $this->pushError($errors, $errorsByType, 'missing_category_id', 'Missing category-id.', $title);
            } else {
                $categoryIds[$categoryId] = true;

                if ($easyEligibleCategoryIds !== null && ! in_array($categoryId, $easyEligibleCategoryIds, true)) {
                    $this->pushError(
                        $errors,
                        $errorsByType,
                        'non_easy_category',
                        "category-id {$categoryId} is not easy-eligible.",
                        $title,
                    );
                }
            }

            if ($categoryName === '') {
                $this->pushError($errors, $errorsByType, 'missing_category_name', 'Missing category-name.', $title);
            }

            if ($title === '') {
                $this->pushError($errors, $errorsByType, 'missing_title', 'Missing title.', $title);
            }

            if ($description === '') {
                $this->pushError($errors, $errorsByType, 'missing_long_description', 'Missing long-description.', $title);
            }

            if (! isset($product->properties) || count($product->properties->property ?? []) === 0) {
                $this->pushError($errors, $errorsByType, 'missing_properties', 'Missing properties.', $title);
            } else {
                $this->validateProperties($product, $categoryId, $title, $errors, $errorsByType);
            }

            $imageCount = 0;
            foreach ($product->colours->colour ?? [] as $colour) {
                foreach ($colour->images->image ?? [] as $image) {
                    $url = trim((string) ($image->url ?? ''));
                    $imageCount++;
                    $normalized = $this->images->normalize($url);

                    if ($normalized['skipped']) {
                        $this->pushError(
                            $errors,
                            $errorsByType,
                            $normalized['reason'] ?? 'invalid_image',
                            "Invalid image URL: {$url}",
                            $title,
                        );
                    }
                }

                foreach ($colour->modifications->modification ?? [] as $modification) {
                    $modificationsChecked++;
                    $this->validateModification($modification, $title, $errors, $errorsByType);
                }
            }

            if ($imageCount === 0) {
                $this->pushError($errors, $errorsByType, 'missing_image', 'Product has no images.', $title);
            }
        }

        return $this->result(
            $errors === [],
            $productsChecked,
            $modificationsChecked,
            count($categoryIds),
            $errorsByType,
            array_slice($errors, 0, 50),
            $warnings,
        );
    }

    /**
     * @param  list<array{type: string, message: string, product_title?: string}>  $errors
     * @param  array<string, int>  $errorsByType
     */
    private function validateProperties(
        SimpleXMLElement $product,
        string $categoryId,
        string $title,
        array &$errors,
        array &$errorsByType,
    ): void {
        $propertyNames = [];

        foreach ($product->properties->property ?? [] as $property) {
            $id = trim((string) ($property->id ?? ''));

            if ($id !== '' && ctype_digit($id)) {
                $this->pushError(
                    $errors,
                    $errorsByType,
                    'numeric_property_id',
                    "Numeric property id used: {$id}",
                    $title,
                );
            }

            if ($id !== '') {
                $propertyNames[mb_strtolower($id)] = true;
            }
        }

        if ($categoryId === '' || ! ctype_digit($categoryId)) {
            return;
        }

        $mandatory = PiguCategoryField::query()
            ->where('lowest_category_id', (int) $categoryId)
            ->where('mandatory', true)
            ->get();

        foreach ($mandatory as $field) {
            $candidates = array_filter([
                mb_strtolower((string) $field->field_title),
                mb_strtolower((string) $field->field_title_en),
            ]);

            $found = false;
            foreach ($candidates as $candidate) {
                if ($candidate !== '' && isset($propertyNames[$candidate])) {
                    $found = true;
                    break;
                }
            }

            if (! $found) {
                $name = (string) ($field->field_title ?: $field->field_title_en ?: $field->field_id);
                $this->pushError(
                    $errors,
                    $errorsByType,
                    'missing_required_property',
                    "Missing required property: {$name}",
                    $title,
                );
            }
        }
    }

    /**
     * @param  list<array{type: string, message: string, product_title?: string}>  $errors
     * @param  array<string, int>  $errorsByType
     */
    private function validateModification(
        SimpleXMLElement $modification,
        string $title,
        array &$errors,
        array &$errorsByType,
    ): void {
        foreach (['weight', 'length', 'height', 'width'] as $dimension) {
            $value = (float) ((string) ($modification->{$dimension} ?? '0'));
            if ($value <= 0) {
                $this->pushError(
                    $errors,
                    $errorsByType,
                    'invalid_dimensions',
                    "Modification {$dimension} must be > 0.",
                    $title,
                );
            }
        }

        $barcode = preg_replace('/\D+/', '', (string) ($modification->attributes->barcodes->barcode ?? '')) ?? '';
        if ($barcode === '' || ! preg_match('/^\d{11,13}$/', $barcode)) {
            $this->pushError($errors, $errorsByType, 'invalid_barcode', 'Modification barcode must be 11–13 digits.', $title);
        }

        $supplierCode = trim((string) ($modification->attributes->{'supplier-code'} ?? ''));
        if ($supplierCode === '') {
            $this->pushError($errors, $errorsByType, 'missing_supplier_code', 'Modification supplier-code is missing.', $title);
        }
    }

    /**
     * @param  list<array{type: string, message: string, product_title?: string}>  $errors
     * @param  array<string, int>  $errorsByType
     */
    private function pushError(
        array &$errors,
        array &$errorsByType,
        string $type,
        string $message,
        ?string $productTitle = null,
    ): void {
        $errorsByType[$type] = ($errorsByType[$type] ?? 0) + 1;
        $row = ['type' => $type, 'message' => $message];

        if ($productTitle !== null && $productTitle !== '') {
            $row['product_title'] = $productTitle;
        }

        $errors[] = $row;
    }

    /**
     * @param  array<string, int>  $errorsByType
     * @param  list<array{type: string, message: string, product_title?: string}>  $errors
     * @param  list<string>  $warnings
     * @return array{
     *     valid: bool,
     *     products_checked: int,
     *     modifications_checked: int,
     *     categories_count: int,
     *     errors_by_type: array<string, int>,
     *     errors: list<array{type: string, message: string, product_title?: string}>,
     *     warnings: list<string>
     * }
     */
    private function result(
        bool $valid,
        int $productsChecked,
        int $modificationsChecked,
        int $categoriesCount,
        array $errorsByType,
        array $errors,
        array $warnings,
    ): array {
        return [
            'valid' => $valid,
            'products_checked' => $productsChecked,
            'modifications_checked' => $modificationsChecked,
            'categories_count' => $categoriesCount,
            'errors_by_type' => $errorsByType,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
}
