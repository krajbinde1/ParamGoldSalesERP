<?php

namespace App\Filament\Resources\ProductionBatches\Pages;

use App\Enums\BomOutputType;
use App\Filament\Resources\ProductionBatches\ProductionBatchResource;
use App\Models\Product;
use App\Models\SemiFinishedMaterial;
use App\Services\Inventory\InventoryUnitConversion;
use App\Services\Inventory\ProductionCostingService;
use App\Services\Inventory\ProductionLabourCost;
use App\Services\Inventory\ProductionService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;

class CreateProductionEntry extends Page implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    protected static string $resource = ProductionBatchResource::class;

    protected static ?string $title = 'New Production Entry';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'production-entry';

    protected string $view = 'filament.resources.production-batches.create-production-entry';

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var list<array<string, mixed>> */
    public array $requirements = [];

    /** @var array<string, mixed>|null */
    public ?array $costing = null;

    public bool $hasMandatoryShortage = false;

    public bool $hasUsageVariance = false;

    public ?string $activeBomLabel = null;

    public ?string $productLabel = null;

    public ?string $productionUnit = null;

    public ?string $productionDateLabel = null;

    public ?float $productionQuantityPreview = null;

    public static function canAccess(array $parameters = []): bool
    {
        return ProductionBatchResource::canPostProduction();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $today = now('Asia/Kolkata')->toDateString();

        $this->form->fill([
            'output_type' => BomOutputType::FinishedProduct->value,
            'production_date' => $today,
            'labour_rate_per_nos' => 0,
            'labour_cost' => 0,
            'transport_cost' => 0,
            'other_manufacturing_cost' => 0,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Production Entry')
                    ->description('Enter production details, then open Review to confirm materials and costs.')
                    ->columns(2)
                    ->schema([
                        Select::make('output_type')
                            ->label('Production Stage')
                            ->options(BomOutputType::options())
                            ->required()
                            ->live()
                            ->helperText(fn ($get): string => ($get('output_type') ?? BomOutputType::FinishedProduct->value) === BomOutputType::SemiFinished->value
                                ? 'Manufacture bulk / semi-finished from the shared raw-material recipe.'
                                : 'Pack a finished SKU. This consumes bulk stock for the selected packing size.')
                            ->afterStateUpdated(function (Set $set): void {
                                $set('product_id', null);
                                $set('semi_finished_id', null);
                            }),
                        Select::make('product_id')
                            ->label('Finished Product / SKU')
                            ->options(fn (): array => Product::query()
                                ->where('status', true)
                                ->orderBy('product_name')
                                ->get()
                                ->mapWithKeys(fn (Product $product): array => [
                                    $product->id => $product->displayLabel(),
                                ])
                                ->all())
                            ->searchable()
                            ->required(fn ($get): bool => ($get('output_type') ?? BomOutputType::FinishedProduct->value) === BomOutputType::FinishedProduct->value)
                            ->visible(fn ($get): bool => ($get('output_type') ?? BomOutputType::FinishedProduct->value) === BomOutputType::FinishedProduct->value)
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set): void {
                                $this->resetReviewState();
                                $this->loadActiveBomLabel(
                                    BomOutputType::FinishedProduct->value,
                                    $state !== null && $state !== '' ? (int) $state : null,
                                    $set,
                                );
                            }),
                        Select::make('semi_finished_id')
                            ->label('Bulk / Semi-Finished')
                            ->options(fn (): array => SemiFinishedMaterial::query()
                                ->where('status', true)
                                ->orderBy('material_name')
                                ->get()
                                ->mapWithKeys(fn (SemiFinishedMaterial $material): array => [
                                    $material->id => trim($material->material_code.' — '.$material->material_name),
                                ])
                                ->all())
                            ->searchable()
                            ->required(fn ($get): bool => ($get('output_type') ?? '') === BomOutputType::SemiFinished->value)
                            ->visible(fn ($get): bool => ($get('output_type') ?? '') === BomOutputType::SemiFinished->value)
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set): void {
                                $this->resetReviewState();
                                $this->loadActiveBomLabel(
                                    BomOutputType::SemiFinished->value,
                                    $state !== null && $state !== '' ? (int) $state : null,
                                    $set,
                                );
                            }),
                        TextInput::make('active_bom_label')
                            ->label('Active BOM')
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder('Select an output item to load the active BOM'),
                        DatePicker::make('production_date')
                            ->label('Production Date')
                            ->native(false)
                            ->required()
                            ->default(fn () => now('Asia/Kolkata')->toDateString()),
                        TextInput::make('production_quantity')
                            ->label('Production Quantity')
                            ->numeric()
                            ->minValue(0.001)
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Set $set, Get $get): void {
                                $this->syncLabourCost($set, $get);
                            })
                            ->suffix(fn (): string => $this->productionUnit ?: '')
                            ->helperText(fn ($get): string => ($get('output_type') ?? BomOutputType::FinishedProduct->value) === BomOutputType::SemiFinished->value
                                ? 'Bulk output quantity in the manufacturing BOM unit (e.g. Kg).'
                                : 'Number of finished packs for this SKU. Bulk is consumed from the packing BOM (e.g. 2 KG bulk per 2 KG bag).'),
                        TextInput::make('labour_rate_per_nos')
                            ->label('Labour Rate Per Nos')
                            ->prefix('₹')
                            ->suffix('/Nos')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Set $set, Get $get): void {
                                $this->syncLabourCost($set, $get);
                            }),
                        TextInput::make('labour_cost')
                            ->label('Total Labour Cost')
                            ->prefix('₹')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->readOnly()
                            ->dehydrated()
                            ->helperText('Production Quantity × Labour Rate Per Nos'),
                        TextInput::make('transport_cost')
                            ->label('Transport Cost')
                            ->prefix('₹')
                            ->numeric()
                            ->minValue(0)
                            ->default(0),
                        TextInput::make('other_manufacturing_cost')
                            ->label('Other Manufacturing Cost')
                            ->prefix('₹')
                            ->numeric()
                            ->minValue(0)
                            ->default(0),
                        Textarea::make('notes')
                            ->label('Remarks')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    private function loadActiveBomLabel(string $outputType, ?int $outputId, Set $set): void
    {
        if (! $outputId) {
            $set('active_bom_label', null);

            return;
        }

        try {
            $preview = app(ProductionService::class)->preview([
                'output_type' => $outputType,
                'product_id' => $outputType === BomOutputType::FinishedProduct->value ? $outputId : null,
                'semi_finished_id' => $outputType === BomOutputType::SemiFinished->value ? $outputId : null,
                'planned_quantity' => 1,
                'actual_output_quantity' => 1,
                'labour_cost' => 0,
                'transport_cost' => 0,
                'other_manufacturing_cost' => 0,
            ]);

            $this->activeBomLabel = (string) $preview['bom']->bom_number;
            $this->productionUnit = (string) ($preview['bom']->batch_unit ?: '');
            $set('active_bom_label', $this->activeBomLabel);
        } catch (ValidationException) {
            $this->activeBomLabel = null;
            $set('active_bom_label', null);
        }
    }

    private function resetReviewState(): void
    {
        $this->requirements = [];
        $this->costing = null;
        $this->hasMandatoryShortage = false;
        $this->hasUsageVariance = false;
        $this->activeBomLabel = null;
        $this->productLabel = null;
        $this->productionUnit = null;
        $this->productionDateLabel = null;
        $this->productionQuantityPreview = null;
    }

    public function prepareReview(): bool
    {
        $data = $this->form->getState();
        $outputType = (string) ($data['output_type'] ?? BomOutputType::FinishedProduct->value);
        $productId = (int) ($data['product_id'] ?? 0);
        $semiFinishedId = (int) ($data['semi_finished_id'] ?? 0);
        $quantity = (float) ($data['production_quantity'] ?? 0);
        $outputId = $outputType === BomOutputType::SemiFinished->value ? $semiFinishedId : $productId;

        if ($outputId <= 0 || $quantity <= 0) {
            Notification::make()
                ->danger()
                ->title('Incomplete production entry')
                ->body('Select an output item and enter a production quantity greater than zero.')
                ->send();

            return false;
        }

        try {
            $preview = app(ProductionService::class)->preview([
                'output_type' => $outputType,
                'product_id' => $productId > 0 ? $productId : null,
                'semi_finished_id' => $semiFinishedId > 0 ? $semiFinishedId : null,
                'planned_quantity' => $quantity,
                'actual_output_quantity' => $quantity,
                'labour_rate_per_nos' => $data['labour_rate_per_nos'] ?? 0,
                'labour_cost' => $this->resolvedLabourCost($data),
                'transport_cost' => $data['transport_cost'] ?? 0,
                'other_manufacturing_cost' => $data['other_manufacturing_cost'] ?? 0,
            ]);

            $product = $preview['product'];
            $semiFinished = $preview['semi_finished'];
            $bom = $preview['bom'];

            $this->requirements = $preview['requirements'];
            $this->costing = $preview['costing'];
            $this->hasMandatoryShortage = $preview['has_mandatory_shortage'];
            $this->hasUsageVariance = (bool) ($preview['has_usage_variance'] ?? false);
            $this->activeBomLabel = (string) $bom->bom_number;
            $this->productLabel = $outputType === BomOutputType::SemiFinished->value
                ? trim(($semiFinished?->material_code ?? '').' — '.($semiFinished?->material_name ?? 'Semi-Finished'))
                : $product->displayLabel();
            $this->productionUnit = (string) ($bom->batch_unit
                ?: ($outputType === BomOutputType::SemiFinished->value
                    ? ($semiFinished?->unit ?: 'Kg')
                    : ($product->production_unit ?: $product->uom ?: 'Nos')));
            $this->productionDateLabel = (string) $data['production_date'];
            $this->data['labour_cost'] = $this->resolvedLabourCost($data);
            $this->productionQuantityPreview = $quantity;
            $this->hydrateActualUsedFormulationQuantities();

            return true;
        } catch (ValidationException $exception) {
            $this->resetReviewState();

            Notification::make()
                ->danger()
                ->title('Unable to prepare production review')
                ->body(collect($exception->errors())->flatten()->first() ?? 'Unable to load BOM for the selected output item.')
                ->send();

            return false;
        }
    }

    /**
     * @return array{rate: float, unit: string, label: string}
     */
    public function displayAverageRate(float $inventoryRate, string $inventoryUnit, string $formulationUnit): array
    {
        $converter = app(InventoryUnitConversion::class);
        $inventoryUnit = $converter->normalize($inventoryUnit);
        $formulationUnit = $converter->normalize($formulationUnit !== '' ? $formulationUnit : $inventoryUnit);

        try {
            if ($converter->areCompatible($formulationUnit, $inventoryUnit) && $formulationUnit !== $inventoryUnit) {
                $factor = $converter->conversionFactor($formulationUnit, $inventoryUnit);
                $rate = round($inventoryRate * $factor, 4);
            } else {
                $rate = round($inventoryRate, 4);
                $formulationUnit = $inventoryUnit;
            }
        } catch (\Throwable) {
            $rate = round($inventoryRate, 4);
            $formulationUnit = $inventoryUnit;
        }

        return [
            'rate' => $rate,
            'unit' => $formulationUnit,
            'label' => '₹'.number_format($rate, 2, '.', ',').'/'.$formulationUnit,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{key: string, label: string, color: string}
     */
    public function displayMaterialStatus(array $row): array
    {
        $available = (float) ($row['available_stock'] ?? 0);
        $required = (float) ($row['required_quantity'] ?? 0);
        $actualUsed = (float) ($row['actual_used_quantity'] ?? $required);
        $minimum = (float) ($row['minimum_stock'] ?? 0);

        if ($actualUsed - $available > 0.0001) {
            return ['key' => 'shortage', 'label' => 'Shortage', 'color' => 'danger'];
        }

        if ($required - $actualUsed > 0.0001) {
            return ['key' => 'variance', 'label' => 'Shortage', 'color' => 'warning'];
        }

        if ($available <= $minimum) {
            return ['key' => 'low', 'label' => 'Low Stock', 'color' => 'warning'];
        }

        return ['key' => 'available', 'label' => 'Available', 'color' => 'success'];
    }

    /**
     * Fill Actual Used Qty in the Required Qty (formulation) UOM for the review table.
     */
    public function hydrateActualUsedFormulationQuantities(): void
    {
        foreach ($this->requirements as $index => $row) {
            $formUnit = $this->formulationUnit($row);
            $invUnit = $this->inventoryUnit($row);
            $invActual = round((float) ($row['actual_used_quantity'] ?? $row['required_quantity'] ?? 0), 6);
            $formActual = $this->convertQuantity($invActual, $invUnit, $formUnit);
            $maxForm = $this->maxActualUsedInFormulation($row);

            if ($formActual < 0) {
                $formActual = 0.0;
            }
            if ($formActual - $maxForm > 0.0001) {
                $formActual = max(0.0, $maxForm);
            }

            $this->requirements[$index]['actual_used_formulation_quantity'] = round($formActual, 4);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function formulationUnit(array $row): string
    {
        $converter = app(InventoryUnitConversion::class);
        $formUnit = trim((string) ($row['formulation_unit'] ?? ''));
        $invUnit = trim((string) ($row['inventory_unit'] ?? $row['unit'] ?? ''));

        return $converter->normalize($formUnit !== '' ? $formUnit : $invUnit);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function inventoryUnit(array $row): string
    {
        return app(InventoryUnitConversion::class)->normalize(
            (string) ($row['inventory_unit'] ?? $row['unit'] ?? ''),
        );
    }

    public function convertQuantity(float $quantity, string $fromUnit, string $toUnit): float
    {
        $converter = app(InventoryUnitConversion::class);
        $from = $converter->normalize($fromUnit);
        $to = $converter->normalize($toUnit);

        if ($from === '' || $to === '' || $from === $to) {
            return round($quantity, 6);
        }

        try {
            return (float) $converter->convert($quantity, $from, $to)['quantity'];
        } catch (\Throwable) {
            return round($quantity, 6);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function requiredQuantityInFormulation(array $row): float
    {
        if (array_key_exists('formulation_quantity', $row) && $row['formulation_quantity'] !== null) {
            return round((float) $row['formulation_quantity'], 6);
        }

        return $this->convertQuantity(
            (float) ($row['required_quantity'] ?? 0),
            $this->inventoryUnit($row),
            $this->formulationUnit($row),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function availableStockInFormulation(array $row): float
    {
        return $this->convertQuantity(
            (float) ($row['available_stock'] ?? 0),
            $this->inventoryUnit($row),
            $this->formulationUnit($row),
        );
    }

    /**
     * Max Actual Used Qty in Required Qty UOM: min(required, available-in-that-UOM).
     *
     * @param  array<string, mixed>  $row
     */
    public function maxActualUsedInFormulation(array $row): float
    {
        $required = $this->requiredQuantityInFormulation($row);
        $available = max(0.0, $this->availableStockInFormulation($row));

        return round(min($required, $available), 6);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function shortageRows(): array
    {
        return array_values(array_filter(
            $this->requirements,
            fn (array $row): bool => ! ($row['is_optional'] ?? false) && (float) ($row['shortage_quantity'] ?? 0) > 0,
        ));
    }

    public function usageVarianceRows(): array
    {
        return array_values(array_filter(
            $this->requirements,
            fn (array $row): bool => ! ($row['is_optional'] ?? false) && ($row['has_usage_variance'] ?? false),
        ));
    }

    /**
     * Recalculate cost, balance, and shortage when Actual Used Qty is edited.
     * Actual Used Qty is entered in the Required Qty (formulation) UOM.
     */
    public function updatedRequirements(mixed $value, string $key): void
    {
        if (str_contains($key, 'actual_used_formulation_quantity')) {
            $this->recostReviewFromActualUsed(fromFormulation: true);

            return;
        }

        if (str_contains($key, 'actual_used_quantity')) {
            $this->recostReviewFromActualUsed(fromFormulation: false);
        }
    }

    public function recostReviewFromActualUsed(bool $fromFormulation = false): void
    {
        foreach ($this->requirements as $index => $row) {
            $formUnit = $this->formulationUnit($row);
            $invUnit = $this->inventoryUnit($row);
            $formRequired = $this->requiredQuantityInFormulation($row);
            $requiredInv = round((float) ($row['required_quantity'] ?? 0), 6);
            $availableInv = round((float) ($row['available_stock'] ?? 0), 6);
            $maxForm = $this->maxActualUsedInFormulation($row);
            $inventoryRate = (float) ($row['average_rate'] ?? 0);

            if ($fromFormulation) {
                $formActual = round((float) ($row['actual_used_formulation_quantity'] ?? 0), 4);
            } else {
                $formActual = $this->convertQuantity(
                    round((float) ($row['actual_used_quantity'] ?? 0), 4),
                    $invUnit,
                    $formUnit,
                );
            }

            if ($formActual < 0) {
                $formActual = 0.0;
            }

            if ($formActual - $maxForm > 0.0001) {
                $formActual = max(0.0, $maxForm);
            }

            $formActual = round($formActual, 4);
            $invActual = round($this->convertQuantity($formActual, $formUnit, $invUnit), 6);
            $rate = $this->displayAverageRate($inventoryRate, $invUnit, $formUnit);
            $varianceInv = round(max(0.0, $requiredInv - $invActual), 4);

            $this->requirements[$index]['actual_used_formulation_quantity'] = $formActual;
            $this->requirements[$index]['actual_used_quantity'] = $invActual;
            $this->requirements[$index]['consumed_quantity'] = $invActual;
            $this->requirements[$index]['balance_after'] = round($availableInv - $invActual, 6);
            $this->requirements[$index]['estimated_value'] = round($formActual * $rate['rate'], 2);
            $this->requirements[$index]['variance_quantity'] = $varianceInv;
            $this->requirements[$index]['has_usage_variance'] = ($formRequired - $formActual) > 0.0001;
            $this->requirements[$index]['shortage_quantity'] = round(max(0.0, $invActual - $availableInv), 4);
        }

        $this->hasMandatoryShortage = collect($this->requirements)->contains(
            fn (array $row): bool => ! ($row['is_optional'] ?? false) && (float) ($row['shortage_quantity'] ?? 0) > 0.0001,
        );
        $this->hasUsageVariance = collect($this->requirements)->contains(
            fn (array $row): bool => ! ($row['is_optional'] ?? false) && ($row['has_usage_variance'] ?? false),
        );

        $quantity = (float) ($this->productionQuantityPreview ?? 0);
        if ($quantity <= 0) {
            return;
        }

        $this->costing = app(ProductionCostingService::class)->calculate(
            array_map(static fn (array $row): array => [
                'item_type' => $row['item_type'],
                'consumption_value' => $row['estimated_value'] ?? 0,
            ], $this->requirements),
            [
                'labour_cost' => $this->data['labour_cost'] ?? 0,
                'transport_cost' => $this->data['transport_cost'] ?? 0,
                'other_manufacturing_cost' => $this->data['other_manufacturing_cost'] ?? 0,
            ],
            $quantity,
        );
    }

    public function reviewProductionAction(): Action
    {
        return Action::make('reviewProduction')
            ->label('Review & Confirm')
            ->color('primary')
            ->icon('heroicon-o-clipboard-document-check')
            ->modalHeading('Production Confirmation')
            ->modalDescription(null)
            ->modalWidth(Width::SevenExtraLarge)
            ->closeModalByClickingAway(false)
            ->stickyModalFooter()
            ->modalFooterActionsAlignment(Alignment::Start)
            ->extraModalWindowAttributes([
                'class' => 'erp-production-confirm-modal',
            ])
            ->disabled(fn (): bool => blank($this->data['product_id'] ?? null) && blank($this->data['semi_finished_id'] ?? null))
            ->mountUsing(function (): void {
                if (! $this->prepareReview()) {
                    throw new Halt;
                }
            })
            ->modalContent(fn (): View => view(
                'filament.resources.production-batches.partials.production-review-panel',
                $this->reviewPanelData(),
            ))
            ->modalSubmitAction(false)
            ->modalCancelAction(false)
            ->modalFooterActions(function (Action $action): array {
                // Left: Back | Center: Preview Ledger | Right: Cancel + Confirm Production
                return [
                    Action::make('backToEntry')
                        ->label('← Back')
                        ->color('gray')
                        ->close(),
                    Action::make('previewLedger')
                        ->label('Preview Ledger')
                        ->color('gray')
                        ->icon('heroicon-o-book-open')
                        ->modalHeading('Ledger Preview')
                        ->modalDescription('Expected stock movements when this production is confirmed. No posting occurs until you confirm.')
                        ->modalWidth(Width::FiveExtraLarge)
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Close')
                        ->modalContent(fn (): View => view(
                            'filament.resources.production-batches.partials.production-ledger-preview',
                            $this->ledgerPreviewData(),
                        )),
                    Action::make('cancelReview')
                        ->label('Cancel')
                        ->color('gray')
                        ->close(),
                    $action->makeModalSubmitAction('confirmProduction')
                        ->label('Confirm Production')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->disabled($this->requirements === [] || $this->hasMandatoryShortage),
                ];
            })
            ->action(function (): void {
                $this->recostReviewFromActualUsed(fromFormulation: true);

                if ($this->requirements === [] || $this->hasMandatoryShortage) {
                    Notification::make()
                        ->danger()
                        ->title('Cannot post production')
                        ->body($this->hasMandatoryShortage
                            ? 'Actual Used Qty cannot exceed available stock for one or more materials.'
                            : 'Review data is missing. Please try again.')
                        ->send();

                    return;
                }

                $this->completeProduction();
            });
    }

    /**
     * @return array<string, mixed>
     */
    public function reviewPanelData(): array
    {
        $materialRows = [];
        $totalMaterialCost = 0.0;

        foreach ($this->requirements as $index => $row) {
            $invUnit = (string) ($row['inventory_unit'] ?? $row['unit'] ?? '');
            $formUnit = (string) ($row['formulation_unit'] ?? $invUnit);
            $status = $this->displayMaterialStatus($row);
            $rate = $this->displayAverageRate(
                (float) ($row['average_rate'] ?? 0),
                $invUnit,
                $formUnit,
            );
            $cost = (float) ($row['estimated_value'] ?? 0);
            $totalMaterialCost += $cost;
            $requiredQty = (float) ($row['required_quantity'] ?? 0);
            $actualUsedInv = (float) ($row['actual_used_quantity'] ?? $requiredQty);
            $available = (float) ($row['available_stock'] ?? 0);
            $formRequired = $this->requiredQuantityInFormulation($row);

            $materialRows[] = [
                'index' => $index,
                'material_name' => $row['material_name'],
                'required_label' => number_format($formRequired, 3).' '.$formUnit,
                'available_stock' => $available,
                'available_label' => number_format($available, 3).' '.$invUnit,
                'inventory_unit' => $invUnit,
                'formulation_unit' => $formUnit,
                'max_actual_used' => $this->maxActualUsedInFormulation($row),
                'has_usage_variance' => (bool) ($row['has_usage_variance'] ?? false),
                'balance_label' => number_format(
                    (float) ($row['balance_after'] ?? ($available - $actualUsedInv)),
                    3,
                ).' '.$invUnit,
                'average_rate_label' => $rate['label'],
                'material_cost' => $cost,
                'status_label' => $status['label'],
                'status_color' => $status['color'],
            ];
        }

        $shortageDisplay = [];
        foreach ($this->shortageRows() as $row) {
            $invUnit = (string) ($row['inventory_unit'] ?? $row['unit'] ?? '');
            $shortageDisplay[] = [
                'material_name' => $row['material_name'],
                'required_label' => number_format((float) ($row['formulation_quantity'] ?? $row['required_quantity']), 3)
                    .' '.($row['formulation_unit'] ?? $invUnit),
                'available_label' => number_format((float) ($row['available_stock'] ?? 0), 3).' '.$invUnit,
                'shortage_label' => number_format((float) ($row['shortage_quantity'] ?? 0), 3).' '.$invUnit,
            ];
        }

        $varianceDisplay = [];
        foreach ($this->usageVarianceRows() as $row) {
            $formUnit = $this->formulationUnit($row);
            $invUnit = $this->inventoryUnit($row);
            $formRequired = $this->requiredQuantityInFormulation($row);
            $formActual = (float) ($row['actual_used_formulation_quantity']
                ?? $this->convertQuantity((float) ($row['actual_used_quantity'] ?? 0), $invUnit, $formUnit));
            $varianceDisplay[] = [
                'material_name' => $row['material_name'],
                'required_label' => number_format($formRequired, 3).' '.$formUnit,
                'actual_used_label' => number_format($formActual, 3).' '.$formUnit,
                'variance_label' => number_format(max(0.0, $formRequired - $formActual), 3).' '.$formUnit,
            ];
        }

        return [
            'productLabel' => $this->productLabel,
            'activeBomLabel' => $this->activeBomLabel,
            'productionQuantity' => $this->productionQuantityPreview,
            'productionUnit' => $this->productionUnit,
            'productionDate' => $this->productionDateLabel,
            'hasMandatoryShortage' => $this->hasMandatoryShortage,
            'hasUsageVariance' => $this->hasUsageVariance,
            'materialRows' => $materialRows,
            'totalMaterialCost' => $totalMaterialCost,
            'shortageRows' => $shortageDisplay,
            'varianceRows' => $varianceDisplay,
            'costing' => $this->costing,
            'showCosts' => $this->canViewProductionCosts(),
            'labourCost' => (float) ($this->data['labour_cost'] ?? 0),
            'labourRatePerNos' => (float) ($this->data['labour_rate_per_nos'] ?? 0),
            'transportCost' => (float) ($this->data['transport_cost'] ?? 0),
            'otherManufacturingCost' => (float) ($this->data['other_manufacturing_cost'] ?? 0),
        ];
    }

    /**
     * Display-only expected ledger movements from the current review preview.
     *
     * @return array<string, mixed>
     */
    public function ledgerPreviewData(): array
    {
        $outLines = [];
        foreach ($this->requirements as $row) {
            if (($row['is_optional'] ?? false) && (float) ($row['shortage_quantity'] ?? 0) > 0) {
                continue;
            }

            $qty = (float) ($row['actual_used_quantity'] ?? $row['consumed_quantity'] ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $outLines[] = [
                'material_name' => $row['material_name'],
                'transaction' => 'Production Consumption',
                'quantity_out' => number_format($qty, 4).' '.($row['inventory_unit'] ?? $row['unit'] ?? ''),
                'value' => (float) ($row['estimated_value'] ?? 0),
            ];
        }

        return [
            'outLines' => $outLines,
            'finishedProduct' => $this->productLabel,
            'finishedQty' => number_format((float) ($this->productionQuantityPreview ?? 0), 3)
                .' '.($this->productionUnit ?? ''),
            'finishedValue' => (float) ($this->costing['total_batch_cost'] ?? 0),
            'showCosts' => $this->canViewProductionCosts(),
        ];
    }

    public function canViewProductionCosts(): bool
    {
        return auth()->user()?->canViewProductionCosts() ?? false;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolvedLabourCost(array $data): float
    {
        $qty = (float) ($data['production_quantity'] ?? $this->productionQuantityPreview ?? 0);

        return ProductionLabourCost::total($qty, (float) ($data['labour_rate_per_nos'] ?? 0));
    }

    private function syncLabourCost(Set $set, Get $get): void
    {
        $qty = (float) ($get('production_quantity') ?? 0);
        $rate = (float) ($get('labour_rate_per_nos') ?? 0);
        $set('labour_cost', ProductionLabourCost::total($qty, $rate));
    }

    protected function completeProduction(): void
    {
        $data = $this->form->getState();

        $payload = [
            'output_type' => $data['output_type'] ?? BomOutputType::FinishedProduct->value,
            'product_id' => $data['product_id'] ?? null,
            'semi_finished_id' => $data['semi_finished_id'] ?? null,
            'production_date' => $data['production_date'],
            'manufacturing_date' => $data['production_date'],
            'planned_quantity' => $data['production_quantity'],
            'actual_output_quantity' => $data['production_quantity'],
            'wastage_quantity' => 0,
            'labour_rate_per_nos' => $data['labour_rate_per_nos'] ?? 0,
            'labour_cost' => $this->resolvedLabourCost($data),
            'electricity_cost' => 0,
            'machine_cost' => 0,
            'processing_cost' => 0,
            'transport_cost' => $data['transport_cost'] ?? 0,
            'other_manufacturing_cost' => $data['other_manufacturing_cost'] ?? 0,
            'notes' => $data['notes'] ?? null,
            'materials' => array_map(static fn (array $row): array => [
                'bom_item_id' => $row['bom_item_id'] ?? null,
                'actual_used_quantity' => $row['actual_used_quantity'] ?? $row['consumed_quantity'] ?? 0,
            ], $this->requirements),
        ];

        try {
            $batch = app(ProductionService::class)->completeProduction($payload, auth()->user());

            $finishedQty = number_format((float) $batch->actual_output_quantity, 3);
            $materialCost = number_format((float) ($batch->total_material_cost ?? 0) + (float) ($batch->total_packaging_cost ?? 0), 2);
            $totalCost = number_format((float) ($batch->total_batch_cost ?? 0), 2);

            Notification::make()
                ->success()
                ->title('✔ Production Completed Successfully')
                ->body("Batch {$batch->batch_number}  ·  Finished Qty {$finishedQty}  ·  Material Cost ₹{$materialCost}  ·  Total Cost ₹{$totalCost}")
                ->send();

            $this->redirect(ProductionBatchResource::getUrl('view', ['record' => $batch]));
        } catch (ValidationException $exception) {
            Notification::make()
                ->danger()
                ->title('Unable to post production')
                ->body(collect($exception->errors())->flatten()->first() ?? 'Unable to post this production entry.')
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToBatches')
                ->label('Back to Production Batches')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn (): string => ProductionBatchResource::getUrl('index')),
        ];
    }
}
