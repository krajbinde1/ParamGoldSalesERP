<?php

namespace App\Filament\Resources\Purchases\Schemas;

use App\Enums\PurchaseMaterialType;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Models\PackagingMaterial;
use App\Models\RawMaterial;
use App\Models\Supplier;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class PurchaseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Purchase details')
                    ->columns(3)
                    ->schema([
                        TextInput::make('purchase_number')
                            ->label('Purchase No.')
                            ->placeholder('Generated automatically when saved')
                            ->readOnly()
                            ->dehydrated(false),
                        DatePicker::make('purchase_date')
                            ->label('Purchase Date')
                            ->native(false)
                            ->required()
                            ->default(fn () => now('Asia/Kolkata')->toDateString()),
                        Select::make('supplier_id')
                            ->label('Supplier Name')
                            ->relationship('supplier', 'supplier_name', fn (Builder $query) => $query->where('status', true))
                            ->searchable()
                            ->preload()
                            ->required()
                            ->createOptionForm([
                                TextInput::make('supplier_name')->label('Supplier Name')->required()->maxLength(255),
                                TextInput::make('phone')->tel()->maxLength(30),
                                TextInput::make('gstin')->maxLength(20),
                            ])
                            ->createOptionUsing(function (array $data): int {
                                return Supplier::query()->create([
                                    ...$data,
                                    'created_by' => auth()->id(),
                                ])->id;
                            })
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set): void {
                                $set('supplier_name', Supplier::query()->find($state)?->supplier_name);
                            }),
                        TextInput::make('supplier_name')->hidden()->dehydrated(),
                        TextInput::make('supplier_invoice_number')
                            ->label('Supplier Invoice No.')
                            ->required()
                            ->maxLength(80),
                        DatePicker::make('supplier_invoice_date')
                            ->label('Supplier Invoice Date')
                            ->native(false),
                        Select::make('material_type')
                            ->label('Material Type')
                            ->options(PurchaseMaterialType::options())
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set, mixed $old): void {
                                if (filled($old) && $old !== $state) {
                                    $set('items', []);
                                }
                            }),
                        Textarea::make('remarks')
                            ->label('Remark')
                            ->rows(2)
                            ->columnSpanFull(),
                        FileUpload::make('invoice_path')
                            ->label('Upload Purchase Invoice')
                            ->directory('purchases/invoices')
                            ->acceptedFileTypes([
                                'application/pdf',
                                'image/jpeg',
                                'image/png',
                            ])
                            ->maxSize(10240)
                            ->columnSpanFull(),
                    ]),
                Section::make('Materials')
                    ->schema([
                        Repeater::make('items')
                            ->label('Purchase items')
                            ->defaultItems(1)
                            ->minItems(1)
                            ->addActionLabel('Add material')
                            ->columns(4)
                            ->live()
                            ->schema([
                                Select::make('raw_material_id')
                                    ->label('Material Name')
                                    ->options(fn (): array => RawMaterial::query()
                                        ->where('status', true)
                                        ->orderBy('material_name')
                                        ->get()
                                        ->mapWithKeys(fn (RawMaterial $material): array => [
                                            $material->id => trim($material->material_code.' — '.$material->material_name),
                                        ])
                                        ->all())
                                    ->searchable()
                                    ->preload()
                                    ->required(fn (Get $get): bool => $get('../../material_type') === PurchaseMaterialType::RawMaterial->value)
                                    ->visible(fn (Get $get): bool => $get('../../material_type') === PurchaseMaterialType::RawMaterial->value
                                        || blank($get('../../material_type')))
                                    ->live()
                                    ->afterStateUpdated(function ($state, Set $set): void {
                                        $material = RawMaterial::query()->find($state);
                                        $set('unit', $material?->unit);
                                        $set('purchase_rate', $material?->purchase_rate ?: null);
                                    }),
                                Select::make('packaging_material_id')
                                    ->label('Material Name')
                                    ->options(fn (): array => PackagingMaterial::query()
                                        ->where('status', true)
                                        ->orderBy('packaging_name')
                                        ->get()
                                        ->mapWithKeys(fn (PackagingMaterial $material): array => [
                                            $material->id => trim($material->packaging_code.' — '.$material->packaging_name),
                                        ])
                                        ->all())
                                    ->searchable()
                                    ->preload()
                                    ->required(fn (Get $get): bool => $get('../../material_type') === PurchaseMaterialType::PackingMaterial->value)
                                    ->visible(fn (Get $get): bool => $get('../../material_type') === PurchaseMaterialType::PackingMaterial->value)
                                    ->live()
                                    ->afterStateUpdated(function ($state, Set $set): void {
                                        $material = PackagingMaterial::query()->find($state);
                                        $set('unit', $material?->unit);
                                        $set('purchase_rate', $material?->purchase_rate ?: null);
                                    }),
                                TextInput::make('quantity')
                                    ->label('Quantity')
                                    ->numeric()
                                    ->minValue(0.001)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (Get $get, Set $set) => self::recalculateLine($set, $get)),
                                TextInput::make('unit')
                                    ->label('UOM')
                                    ->readOnly()
                                    ->dehydrated(),
                                TextInput::make('purchase_rate')
                                    ->label('Purchase Rate')
                                    ->helperText('Excluding GST. Used for weighted average stock rate.')
                                    ->numeric()
                                    ->prefix('₹')
                                    ->minValue(0)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (Get $get, Set $set) => self::recalculateLine($set, $get)),
                                TextInput::make('taxable_amount')
                                    ->label('Taxable Amount')
                                    ->prefix('₹')
                                    ->readOnly()
                                    ->dehydrated(),
                                Select::make('gst_percentage')
                                    ->label('GST %')
                                    ->options(ProductForm::gstOptions())
                                    ->formatStateUsing(fn (mixed $state): ?string => ProductForm::normalizeGstKey($state))
                                    ->default('0')
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(fn (Get $get, Set $set) => self::recalculateLine($set, $get)),
                                TextInput::make('gst_amount')
                                    ->label('GST Amount')
                                    ->prefix('₹')
                                    ->readOnly()
                                    ->dehydrated(),
                                TextInput::make('total_amount')
                                    ->label('Total Amount')
                                    ->prefix('₹')
                                    ->readOnly()
                                    ->dehydrated(),
                                TextInput::make('batch_lot_no')
                                    ->label('Batch/Lot No.')
                                    ->maxLength(80),
                                TextInput::make('remarks')
                                    ->label('Remark')
                                    ->maxLength(255)
                                    ->columnSpan(2),
                            ]),
                    ]),
            ]);
    }

    /**
     * @param  array<string, mixed>|Get  $state
     */
    public static function recalculateLine(Set $set, array|Get $state): void
    {
        $qty = (float) (is_array($state) ? ($state['quantity'] ?? 0) : ($state('quantity') ?? 0));
        $rate = (float) (is_array($state) ? ($state['purchase_rate'] ?? 0) : ($state('purchase_rate') ?? 0));
        $gst = (float) (is_array($state) ? ($state['gst_percentage'] ?? 0) : ($state('gst_percentage') ?? 0));

        $taxable = round($qty * $rate, 2);
        $gstAmount = round($taxable * $gst / 100, 2);
        $total = round($taxable + $gstAmount, 2);

        $set('taxable_amount', number_format($taxable, 2, '.', ''));
        $set('gst_amount', number_format($gstAmount, 2, '.', ''));
        $set('total_amount', number_format($total, 2, '.', ''));
    }
}
