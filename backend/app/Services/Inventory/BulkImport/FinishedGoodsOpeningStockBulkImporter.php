<?php

namespace App\Services\Inventory\BulkImport;

use App\Enums\InventoryBulkImportType;
use App\Models\Product;
use App\Models\User;
use App\Services\Inventory\FinishedProductCreateService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Opening-stock-only import for existing Sales Products.
 *
 * Match key: Product Code (products.product_code).
 * Never creates Product rows, production, or material inward.
 */
final class FinishedGoodsOpeningStockBulkImporter extends AbstractMaterialBulkImporter
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
        return InventoryBulkImportType::FinishedGoodsOpeningStock;
    }

    /**
     * Pre-filled template rows with no opening values are ignored (not errors).
     */
    protected function shouldIgnoreRow(array $data): bool
    {
        return $this->blank($data['opening_quantity'] ?? null)
            && $this->blank($data['opening_value'] ?? null)
            && $this->blank($data['opening_date'] ?? null);
    }

    protected function validateRow(array $data, array &$seenNames): ?string
    {
        $missing = $this->missingRequired(['product_code'], $data);
        if ($missing !== []) {
            return 'Missing mandatory field: Product Code.';
        }

        $code = Str::upper(trim($this->stringValue($data['product_code'])));
        if ($code === '') {
            return 'Product Code is required.';
        }

        if (isset($seenNames[$code])) {
            return 'Duplicate Product Code in Excel.';
        }
        $seenNames[$code] = true;

        $product = $this->findSalesProductByCode($code);
        if ($product === null) {
            return 'Product Code does not match an existing Sales Product.';
        }

        $templateName = trim($this->stringValue($data['product_name'] ?? ''));
        $actualName = trim((string) $product->product_name);
        if ($templateName !== '' && Str::lower($templateName) !== Str::lower($actualName)) {
            return 'Product Name does not match Product Code.';
        }

        if ($this->createService->hasOpeningStock($product)) {
            return 'Opening stock already exists for this Finished Product.';
        }

        $opening = $this->resolveOpening($data);
        if (is_string($opening)) {
            return $opening;
        }

        if ((float) $opening['quantity'] <= 0) {
            return 'Opening Stock Quantity must be greater than zero.';
        }

        return null;
    }

    protected function persistRow(array $data, User $user): array
    {
        $opening = $this->resolveOpening($data);
        if (is_string($opening)) {
            throw ValidationException::withMessages(['import' => $opening]);
        }

        if ((float) $opening['quantity'] <= 0) {
            throw ValidationException::withMessages([
                'opening_quantity' => 'Opening Stock Quantity must be greater than zero.',
            ]);
        }

        $code = Str::upper(trim($this->stringValue($data['product_code'])));
        $product = $this->findSalesProductByCode($code);
        if ($product === null) {
            throw ValidationException::withMessages([
                'product_code' => 'Product Code does not match an existing Sales Product.',
            ]);
        }

        $updated = $this->createService->applyOpeningStockToExisting(
            product: $product,
            opening: [
                ...$opening,
                'remarks' => 'Opening Stock',
            ],
            user: $user,
        );

        $qty = (float) $opening['quantity'];
        $value = (float) $opening['value'];
        $rate = $qty > 0 ? round($value / $qty, 4) : 0.0;

        return [
            'imported' => true,
            'opening_ledger' => true,
            'stock_updated' => true,
            'skipped' => false,
            'mapping' => [
                'product_code' => $updated->product_code,
                'product_name' => $updated->product_name,
                'opening_quantity' => number_format($qty, 3, '.', ''),
                'opening_value' => number_format($value, 2, '.', ''),
                'opening_rate' => number_format($rate, 4, '.', ''),
                'status' => 'Success',
            ],
        ];
    }

    private function findSalesProductByCode(string $code): ?Product
    {
        return Product::query()
            ->whereRaw('UPPER(product_code) = ?', [$code])
            ->first();
    }
}
