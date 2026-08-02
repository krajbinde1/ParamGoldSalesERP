<?php

namespace App\Services\Inventory\BulkImport;

use App\Enums\InventoryBulkImportType;
use App\Models\FinishedProduct;
use App\Models\Product;
use App\Models\User;
use App\Services\Inventory\FinishedProductCreateService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class FinishedProductBulkImporter extends AbstractMaterialBulkImporter
{
    public function __construct(
        private readonly FinishedProductCreateService $createService = new FinishedProductCreateService,
        InventoryBulkImportReader $reader = new InventoryBulkImportReader,
        int $chunkSize = 100,
    ) {
        parent::__construct($reader, $chunkSize);
    }

    protected function type(): InventoryBulkImportType
    {
        return InventoryBulkImportType::FinishedProduct;
    }

    protected function validateRow(array $data, array &$seenNames): ?string
    {
        $missing = $this->missingRequired(['existing_product'], $data);
        if ($missing !== []) {
            return 'Missing mandatory field: '.implode(', ', $missing).'.';
        }

        $lookup = $this->stringValue($data['existing_product']);
        $lookupKey = $this->normalizeNameKey($lookup);

        if (isset($seenNames[$lookupKey])) {
            return 'Duplicate existing product mapping in Excel.';
        }
        $seenNames[$lookupKey] = true;

        $product = $this->findSalesProduct($lookup);
        if ($product === false) {
            return 'Multiple products match this name — use Product Code instead.';
        }
        if ($product === null) {
            return 'Existing Product not found (match by product name or code).';
        }

        if (
            FinishedProduct::query()->where('product_id', $product->id)->exists()
            || $product->manufacturing_enabled
            || (float) $product->current_finished_stock > 0
        ) {
            return 'Product is already linked as Finished Product Master.';
        }

        $minimum = $this->parseDecimal($data['minimum_stock'] ?? null, 0.0);
        if ($minimum === null || $minimum < 0) {
            return 'Minimum Stock must be a non-negative number.';
        }

        if ($this->parseYesNo($data['active'] ?? null, true) === null) {
            return 'Active must be Yes or No.';
        }

        $opening = $this->resolveOpening($data);
        if (is_string($opening)) {
            return $opening;
        }

        return null;
    }

    protected function persistRow(array $data, User $user): array
    {
        $opening = $this->resolveOpening($data);
        if (is_string($opening)) {
            throw ValidationException::withMessages(['import' => $opening]);
        }

        $product = $this->findSalesProduct($this->stringValue($data['existing_product']));
        if (! $product instanceof Product) {
            throw ValidationException::withMessages([
                'existing_product' => $product === false
                    ? 'Multiple products match this name — use Product Code instead.'
                    : 'Existing Product not found (match by product name or code).',
            ]);
        }

        $unit = filled($product->production_unit)
            ? (string) $product->production_unit
            : (string) ($product->uom ?: 'Nos');

        // Always link the existing sales product — never create a duplicate Product.
        $linked = $this->createService->create(
            productData: [
                'linked_product_id' => $product->id,
                'product_name' => $product->product_name,
                'unit' => $unit,
                'production_unit' => $unit,
                'minimum_finished_stock' => $this->parseDecimal($data['minimum_stock'] ?? null, 0.0) ?? 0,
                'status' => $this->parseYesNo($data['active'] ?? null, true) ?? true,
                'remarks' => $this->blank($data['remarks'] ?? null) ? null : $this->stringValue($data['remarks']),
                'batch_tracking_enabled' => true,
                'expiry_tracking_enabled' => false,
            ],
            opening: $opening,
            user: $user,
        );

        $hasOpening = (float) $opening['quantity'] > 0;
        $linked->loadMissing('finishedProduct');
        $fp = $linked->finishedProduct;

        return [
            'imported' => true,
            'opening_ledger' => $hasOpening,
            'stock_updated' => $hasOpening,
            'skipped' => false,
            'mapping' => [
                'finished_product_code' => $fp?->finished_product_code,
                'product_code' => $linked->product_code,
                'product_name' => $linked->product_name,
                'unit' => $fp?->unit ?: ($linked->production_unit ?: $linked->uom),
                'current_stock' => number_format((float) $linked->current_finished_stock, 3, '.', ''),
            ],
        ];
    }

    /**
     * Match: 1) Existing Product Code 2) else exact normalized Product Name.
     *
     * @return Product|false|null Product on unique match, false on ambiguous name, null when missing.
     */
    private function findSalesProduct(string $lookup): Product|false|null
    {
        $code = Str::upper(trim($lookup));

        $byCode = Product::query()
            ->whereRaw('UPPER(product_code) = ?', [$code])
            ->first();

        if ($byCode !== null) {
            return $byCode;
        }

        $matches = Product::query()
            ->whereRaw('LOWER(product_name) = ?', [Str::lower(trim($lookup))])
            ->get();

        if ($matches->count() === 1) {
            return $matches->first();
        }

        if ($matches->count() > 1) {
            return false;
        }

        return null;
    }
}
