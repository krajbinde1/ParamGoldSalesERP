<?php

namespace App\Models;

use App\Enums\BomOutputType;
use App\Enums\BomStatus;
use App\Models\Concerns\EnforcesSafeDelete;
use App\Services\Inventory\BOMCalculationService;
use App\Services\Inventory\InventoryCodeGenerator;
use App\Support\IndianCurrency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bom extends Model
{
    use EnforcesSafeDelete;

    protected $attributes = [
        'status' => 'active',
        'output_type' => 'finished_product',
        'standard_batch_size' => 1,
        'output_quantity' => 1,
        'batch_quantity' => 1,
        'batch_unit' => 'Nos',
        'wastage_percentage' => 0,
    ];

    protected static function booted(): void
    {
        static::creating(function (Bom $bom): void {
            if (! filled($bom->bom_number)) {
                $bom->bom_number = app(InventoryCodeGenerator::class)
                    ->nextBomNumber();
            }
        });

        static::saved(function (Bom $bom): void {
            if ($bom->output_type !== BomOutputType::FinishedProduct) {
                return;
            }

            if ($bom->status !== BomStatus::Active) {
                return;
            }

            if ($bom->product_id === null) {
                return;
            }

            Product::query()
                ->whereKey($bom->product_id)
                ->where('manufacturing_enabled', false)
                ->update(['manufacturing_enabled' => true]);
        });
    }

    protected $fillable = [
        'bom_number',
        'output_type',
        'product_id',
        'semi_finished_id',
        'standard_batch_size',
        'output_quantity',
        'batch_quantity',
        'batch_unit',
        'effective_date',
        'status',
        'wastage_percentage',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'output_type' => BomOutputType::class,
            'standard_batch_size' => 'decimal:3',
            'output_quantity' => 'decimal:3',
            'batch_quantity' => 'decimal:3',
            'effective_date' => 'date',
            'status' => BomStatus::class,
            'wastage_percentage' => 'decimal:3',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function semiFinished(): BelongsTo
    {
        return $this->belongsTo(SemiFinishedMaterial::class, 'semi_finished_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BomItem::class)->orderBy('sort_order');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function productionBatches(): HasMany
    {
        return $this->hasMany(ProductionBatch::class);
    }

    public function isActive(): bool
    {
        return $this->status === BomStatus::Active;
    }

    public function isSemiFinishedOutput(): bool
    {
        return $this->output_type === BomOutputType::SemiFinished;
    }

    public function outputName(): string
    {
        if ($this->isSemiFinishedOutput()) {
            return (string) ($this->semiFinished?->material_name ?? 'Semi-Finished');
        }

        return (string) ($this->product?->product_name ?? 'Finished Product');
    }

    public function formulaQuantityLabel(): string
    {
        $qty = rtrim(rtrim(number_format((float) $this->batch_quantity, 3, '.', ''), '0'), '.');

        return trim($qty.' '.((string) $this->batch_unit));
    }

    public function estimatedCostPerUnitLabel(): string
    {
        $value = $this->formulaSummary()['estimated_cost_per_finished_unit'] ?? null;

        return $value === null ? '—' : IndianCurrency::formatExact($value);
    }

    /** @var array<string, mixed>|null */
    private ?array $memoizedFormulaSummary = null;

    /**
     * @return array<string, mixed>
     */
    public function formulaSummary(): array
    {
        if ($this->memoizedFormulaSummary !== null) {
            return $this->memoizedFormulaSummary;
        }

        $this->loadMissing(['items.rawMaterial', 'items.packagingMaterial', 'items.semiFinished']);

        return $this->memoizedFormulaSummary = app(BOMCalculationService::class)
            ->summarizeBom($this, $this->items);
    }
}
