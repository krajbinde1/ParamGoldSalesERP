<?php

namespace App\Filament\Resources\ProductionBatches\Pages;

use App\Enums\BomOutputType;
use App\Filament\Resources\ProductionBatches\ProductionBatchResource;
use App\Models\Product;
use App\Models\SemiFinishedMaterial;
use App\Services\Inventory\InventoryUnitConversion;
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
                            ->suffix(fn (): string => $this->productionUnit ?: '')
                            ->helperText(fn ($get): string => ($get('output_type') ?? BomOutputType::FinishedProduct->value) === BomOutputType::SemiFinished->value
                                ? 'Bulk output quantity in the manufacturing BOM unit (e.g. Kg).'
                                : 'Number of finished packs for this SKU. Bulk is consumed from the packing BOM (e.g. 2 KG bulk per 2 KG bag).'),
                        TextInput::make('labour_cost')
                            ->label('Labour Cost')
                            ->prefix('₹')
                            ->numeric()
                            ->minValue(0)
                            ->default(0),
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
                'labour_cost' => $data['labour_cost'] ?? 0,
                'transport_cost' => $data['transport_cost'] ?? 0,
                'other_manufacturing_cost' => $data['other_manufacturing_cost'] ?? 0,
            ]);

            $product = $preview['product'];
            $semiFinished = $preview['semi_finished'];
            $bom = $preview['bom'];

            $this->requirements = $preview['requirements'];
            $this->costing = $preview['costing'];
            $this->hasMandatoryShortage = $preview['has_mandatory_shortage'];
            $this->activeBomLabel = (string) $bom->bom_number;
            $this->productLabel = $outputType === BomOutputType::SemiFinished->value
                ? trim(($semiFinished?->material_code ?? '').' — '.($semiFinished?->material_name ?? 'Semi-Finished'))
                : $product->displayLabel();
            $this->productionUnit = (string) ($bom->batch_unit
                ?: ($outputType === BomOutputType::SemiFinished->value
                    ? ($semiFinished?->unit ?: 'Kg')
                    : ($product->production_unit ?: $product->uom ?: 'Nos')));
            $this->productionDateLabel = (string) $data['production_date'];
            $this->productionQuantityPreview = $quantity;

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
        $minimum = (float) ($row['minimum_stock'] ?? 0);

        if ($available < $required) {
            return ['key' => 'shortage', 'label' => 'Shortage', 'color' => 'danger'];
        }

        if ($available <= $minimum) {
            return ['key' => 'low', 'label' => 'Low Stock', 'color' => 'warning'];
        }

        return ['key' => 'available', 'label' => 'Available', 'color' => 'success'];
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
                if ($this->requirements === [] || $this->hasMandatoryShortage) {
                    Notification::make()
                        ->danger()
                        ->title('Cannot post production')
                        ->body($this->hasMandatoryShortage
                            ? 'Insufficient stock for one or more materials.'
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

        foreach ($this->requirements as $row) {
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

            $materialRows[] = [
                'material_name' => $row['material_name'],
                'required_label' => number_format((float) ($row['formulation_quantity'] ?? $row['required_quantity']), 3)
                    .' '.($row['formulation_unit'] ?? $formUnit),
                'available_label' => number_format((float) ($row['available_stock'] ?? 0), 3).' '.$invUnit,
                'balance_label' => number_format(
                    (float) ($row['balance_after'] ?? ((float) ($row['available_stock'] ?? 0) - (float) ($row['required_quantity'] ?? 0))),
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

        return [
            'productLabel' => $this->productLabel,
            'activeBomLabel' => $this->activeBomLabel,
            'productionQuantity' => $this->productionQuantityPreview,
            'productionUnit' => $this->productionUnit,
            'productionDate' => $this->productionDateLabel,
            'hasMandatoryShortage' => $this->hasMandatoryShortage,
            'materialRows' => $materialRows,
            'totalMaterialCost' => $totalMaterialCost,
            'shortageRows' => $shortageDisplay,
            'costing' => $this->costing,
            'showCosts' => $this->canViewProductionCosts(),
            'labourCost' => (float) ($this->data['labour_cost'] ?? 0),
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

            $qty = (float) ($row['required_quantity'] ?? 0);
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
            'labour_cost' => $data['labour_cost'] ?? 0,
            'electricity_cost' => 0,
            'machine_cost' => 0,
            'processing_cost' => 0,
            'transport_cost' => $data['transport_cost'] ?? 0,
            'other_manufacturing_cost' => $data['other_manufacturing_cost'] ?? 0,
            'notes' => $data['notes'] ?? null,
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
