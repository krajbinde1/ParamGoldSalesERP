<?php

namespace App\Filament\Resources\PackagingMaterialInwards\Tables;

use App\Enums\RawMaterialInwardStatus;
use App\Filament\Resources\PackagingMaterialInwards\PackagingMaterialInwardResource;
use App\Models\PackagingMaterial;
use App\Models\PackagingMaterialInward;
use App\Services\Inventory\PackagingMaterialInwardService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class PackagingMaterialInwardsTable
{
    public static function configure(Table $table): Table
    {
        $canViewRates = PackagingMaterialInwardResource::canViewRates();

        return $table
            ->defaultSort('inward_date', 'desc')
            ->columns([
                TextColumn::make('inward_number')
                    ->label('Inward No.')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('inward_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('supplier_name')
                    ->label('Supplier')
                    ->formatStateUsing(fn (?string $state, PackagingMaterialInward $record): string => $record->displaySupplierName())
                    ->searchable(),
                TextColumn::make('supplier_invoice_number')
                    ->label('Invoice No.')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('total_items')
                    ->label('Items')
                    ->sortable(),
                TextColumn::make('total_accepted_qty')
                    ->label('Total Qty')
                    ->numeric(3)
                    ->sortable(),
                TextColumn::make('grand_total')
                    ->label('Total Value')
                    ->money('INR')
                    ->sortable()
                    ->visible($canViewRates),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof RawMaterialInwardStatus ? $state->label() : (string) $state)
                    ->color(fn (PackagingMaterialInward $record): string => $record->status->color()),
                TextColumn::make('createdBy.name')
                    ->label('Created By')
                    ->toggleable(),
            ])
            ->filters([
                Filter::make('inward_date')
                    ->form([
                        DatePicker::make('from')->label('From')->native(false),
                        DatePicker::make('until')->label('Until')->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('inward_date', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('inward_date', '<=', $date));
                    }),
                SelectFilter::make('status')
                    ->options(RawMaterialInwardStatus::options()),
                SelectFilter::make('supplier_id')
                    ->relationship('supplier', 'supplier_name')
                    ->searchable()
                    ->preload(false)
                    ->label('Supplier'),
                SelectFilter::make('created_by')
                    ->label('Created By')
                    ->relationship('createdBy', 'name')
                    ->searchable()
                    ->preload(false),
                Filter::make('packaging_material')
                    ->form([
                        Select::make('packaging_material_id')
                            ->label('Packaging Material')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => PackagingMaterial::query()
                                ->where('packaging_name', 'like', "%{$search}%")
                                ->orderBy('packaging_name')
                                ->limit(50)
                                ->pluck('packaging_name', 'id')
                                ->all())
                            ->getOptionLabelUsing(fn ($value): ?string => PackagingMaterial::query()->whereKey($value)->value('packaging_name')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $id = $data['packaging_material_id'] ?? null;
                        if (! $id) {
                            return $query;
                        }

                        return $query->whereHas('items', fn (Builder $q) => $q->where('packaging_material_id', $id));
                    }),
                Filter::make('invoice_number')
                    ->form([
                        TextInput::make('supplier_invoice_number')->label('Invoice Number'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['supplier_invoice_number'] ?? null;
                        if (! filled($value)) {
                            return $query;
                        }

                        return $query->where('supplier_invoice_number', 'like', '%'.$value.'%');
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->form([
                        Textarea::make('cancellation_reason')->label('Reason')->required(),
                    ])
                    ->visible(fn (PackagingMaterialInward $record): bool => auth()->user()?->can('cancel', $record) ?? false)
                    ->action(function (PackagingMaterialInward $record, array $data): void {
                        try {
                            app(PackagingMaterialInwardService::class)->cancel($record, auth()->user(), $data['cancellation_reason'] ?? null);
                            Notification::make()->title('Inward cancelled')->success()->send();
                        } catch (ValidationException $e) {
                            Notification::make()->title(collect($e->errors())->flatten()->first() ?? $e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25);
    }
}
