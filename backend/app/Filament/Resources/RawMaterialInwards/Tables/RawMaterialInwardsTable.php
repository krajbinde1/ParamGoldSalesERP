<?php

namespace App\Filament\Resources\RawMaterialInwards\Tables;

use App\Enums\RawMaterialInwardStatus;
use App\Filament\Resources\RawMaterialInwards\RawMaterialInwardResource;
use App\Models\RawMaterial;
use App\Models\RawMaterialInward;
use App\Services\Inventory\RawMaterialInwardService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class RawMaterialInwardsTable
{
    public static function configure(Table $table): Table
    {
        $canViewRates = RawMaterialInwardResource::canViewRates();

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
                    ->formatStateUsing(fn (?string $state, RawMaterialInward $record): string => $record->displaySupplierName())
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
                    ->color(fn (RawMaterialInward $record): string => $record->status->color()),
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
                Filter::make('raw_material')
                    ->form([
                        Select::make('raw_material_id')
                            ->label('Raw Material')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => RawMaterial::query()
                                ->where('material_name', 'like', "%{$search}%")
                                ->orderBy('material_name')
                                ->limit(50)
                                ->pluck('material_name', 'id')
                                ->all())
                            ->getOptionLabelUsing(fn ($value): ?string => RawMaterial::query()->whereKey($value)->value('material_name')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $id = $data['raw_material_id'] ?? null;
                        if (! $id) {
                            return $query;
                        }

                        return $query->whereHas('items', fn (Builder $q) => $q->where('raw_material_id', $id));
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
                ViewAction::make()
                    ->url(fn (RawMaterialInward $record): string => RawMaterialInwardResource::getUrl('view', ['record' => $record])),
                EditAction::make()
                    ->url(fn (RawMaterialInward $record): string => RawMaterialInwardResource::getUrl('edit', ['record' => $record]))
                    // List UI must not call canEdit()/hasSubsequentStockTransactions() per row —
                    // that scans stock ledgers and can stall the whole admin table render.
                    ->visible(fn (RawMaterialInward $record): bool => RawMaterialInwardResource::canSeeEditAction($record))
                    ->tooltip(fn (RawMaterialInward $record): ?string => match (true) {
                        $record->isEditable() => null,
                        $record->status === RawMaterialInwardStatus::Posted => 'Posted inward — locked if stock was used afterward (checked on Edit).',
                        default => 'This inward cannot be edited.',
                    }),
                Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->form([
                        Textarea::make('cancellation_reason')->label('Reason')->required(),
                    ])
                    ->visible(fn (RawMaterialInward $record): bool => auth()->user()?->can('cancel', $record) ?? false)
                    ->action(function (RawMaterialInward $record, array $data): void {
                        try {
                            app(RawMaterialInwardService::class)->cancel($record, auth()->user(), $data['cancellation_reason'] ?? null);
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
