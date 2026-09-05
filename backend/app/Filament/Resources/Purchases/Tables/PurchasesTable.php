<?php

namespace App\Filament\Resources\Purchases\Tables;

use App\Enums\PurchaseMaterialType;
use App\Enums\PurchaseStatus;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\Purchase;
use App\Services\Inventory\PurchaseService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class PurchasesTable
{
    public static function configure(Table $table): Table
    {
        $canViewRates = PurchaseResource::canViewRates();

        return $table
            ->defaultSort('purchase_date', 'desc')
            ->columns([
                TextColumn::make('purchase_number')
                    ->label('Purchase No.')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('purchase_date')
                    ->label('Date')
                    ->date()
                    ->sortable(),
                TextColumn::make('supplier_name')
                    ->label('Supplier')
                    ->formatStateUsing(fn (?string $state, Purchase $record): string => $record->displaySupplierName())
                    ->searchable(),
                TextColumn::make('supplier_invoice_number')
                    ->label('Invoice No.')
                    ->searchable(),
                TextColumn::make('material_type')
                    ->label('Material Type')
                    ->formatStateUsing(fn ($state) => $state instanceof PurchaseMaterialType ? $state->label() : (string) $state)
                    ->badge(),
                TextColumn::make('total_quantity')
                    ->label('Total Qty')
                    ->numeric(3)
                    ->sortable(),
                TextColumn::make('total_taxable_amount')
                    ->label('Taxable Amount')
                    ->money('INR')
                    ->sortable()
                    ->visible($canViewRates),
                TextColumn::make('total_gst')
                    ->label('GST')
                    ->money('INR')
                    ->sortable()
                    ->visible($canViewRates),
                TextColumn::make('grand_total')
                    ->label('Supplier Bill Grand Total')
                    ->money('INR')
                    ->sortable()
                    ->visible($canViewRates),
                TextColumn::make('transport_cost')
                    ->label('Transport/Freight Cost')
                    ->money('INR')
                    ->sortable()
                    ->visible($canViewRates),
                TextColumn::make('total_landed_cost')
                    ->label('Total Landed Cost')
                    ->money('INR')
                    ->sortable()
                    ->toggleable()
                    ->visible($canViewRates),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof PurchaseStatus ? $state->label() : (string) $state)
                    ->color(fn (Purchase $record): string => $record->status->color()),
            ])
            ->filters([
                Filter::make('purchase_date')
                    ->label('Date Range')
                    ->form([
                        DatePicker::make('from')->label('From')->native(false),
                        DatePicker::make('until')->label('Until')->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('purchase_date', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('purchase_date', '<=', $date));
                    }),
                SelectFilter::make('supplier_id')
                    ->relationship('supplier', 'supplier_name')
                    ->searchable()
                    ->preload()
                    ->label('Supplier'),
                SelectFilter::make('material_type')
                    ->label('Raw Material / Packing Material')
                    ->options(PurchaseMaterialType::options()),
                SelectFilter::make('status')
                    ->options(PurchaseStatus::options()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (Purchase $record): bool => PurchaseResource::canSeeEditAction($record)),
                Action::make('confirm')
                    ->label('Confirm')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Confirm purchase?')
                    ->modalDescription('Stock will be added to the selected material masters.')
                    ->visible(fn (Purchase $record): bool => auth()->user()?->can('confirm', $record) ?? false)
                    ->action(function (Purchase $record): void {
                        try {
                            app(PurchaseService::class)->confirm($record, auth()->user());
                            Notification::make()->title('Purchase confirmed and stock updated.')->success()->send();
                        } catch (ValidationException $e) {
                            Notification::make()->title(collect($e->errors())->flatten()->first() ?? $e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('cancel')
                    ->label('Cancel')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->form([
                        Textarea::make('cancellation_reason')->label('Reason')->required(),
                    ])
                    ->visible(fn (Purchase $record): bool => auth()->user()?->can('cancel', $record) ?? false)
                    ->action(function (Purchase $record, array $data): void {
                        try {
                            app(PurchaseService::class)->cancel($record, auth()->user(), $data['cancellation_reason'] ?? null);
                            Notification::make()->title('Purchase cancelled.')->success()->send();
                        } catch (ValidationException $e) {
                            Notification::make()->title(collect($e->errors())->flatten()->first() ?? $e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25);
    }
}
