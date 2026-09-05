<?php

namespace App\Filament\Resources\TransportFreightLedgers\Tables;

use App\Enums\TransportFreightLedgerType;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\TransportFreightLedger;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TransportFreightLedgersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('transaction_date')
                    ->label('Purchase Date')
                    ->date()
                    ->sortable(),
                TextColumn::make('purchase_number')
                    ->label('Purchase No.')
                    ->searchable()
                    ->url(fn (TransportFreightLedger $record): string => PurchaseResource::getUrl('view', ['record' => $record->purchase_id])),
                TextColumn::make('supplier_name')
                    ->label('Supplier')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('transporter_name')
                    ->label('Transporter Name')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('amount')
                    ->label('Transport Amount')
                    ->money('INR')
                    ->sortable(),
                TextColumn::make('transport_invoice_lr_no')
                    ->label('LR/Invoice No.')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('transaction_type')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof TransportFreightLedgerType ? $state->label() : (string) $state)
                    ->color(fn (TransportFreightLedger $record): string => $record->transaction_type->color()),
                TextColumn::make('remarks')
                    ->label('Remark')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                Filter::make('transaction_date')
                    ->label('Date Range')
                    ->form([
                        DatePicker::make('from')->label('From')->native(false),
                        DatePicker::make('until')->label('Until')->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('transaction_date', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('transaction_date', '<=', $date));
                    }),
                SelectFilter::make('transaction_type')
                    ->options(TransportFreightLedgerType::options()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25);
    }
}
