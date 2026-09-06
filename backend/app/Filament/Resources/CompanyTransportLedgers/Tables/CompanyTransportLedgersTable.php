<?php

namespace App\Filament\Resources\CompanyTransportLedgers\Tables;

use App\Enums\CompanyTransportExpenseType;
use App\Enums\TransportChargeType;
use App\Filament\Resources\CompanyTransportLedgers\CompanyTransportLedgerResource;
use App\Models\CompanyTransportLedgerEntry;
use App\Support\IndianCurrency;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CompanyTransportLedgersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('transaction_date', 'asc')
            ->modifyQueryUsing(function (Builder $query): Builder {
                return $query
                    ->select('company_transport_ledger_entries.*')
                    ->selectRaw(
                        'ROUND(SUM(credit_amount - debit_amount) OVER (ORDER BY transaction_date ASC, id ASC), 2) as running_balance',
                    );
            })
            ->columns([
                TextColumn::make('transaction_date')
                    ->label('Date')
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('particulars')
                    ->label('Particulars')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('order_no')
                    ->label('Order No.')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('transport_charge_type')
                    ->label('Transport Type')
                    ->formatStateUsing(fn ($state, CompanyTransportLedgerEntry $record): string => $record->transportTypeLabel() ?: '—')
                    ->placeholder('—'),
                TextColumn::make('vehicle_number')
                    ->label('Vehicle No.')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('debit_amount')
                    ->label('Debit')
                    ->alignRight()
                    ->formatStateUsing(fn ($state): string => (float) $state > 0.004
                        ? IndianCurrency::formatExact((float) $state)
                        : ''),
                TextColumn::make('credit_amount')
                    ->label('Credit')
                    ->alignRight()
                    ->formatStateUsing(fn ($state): string => (float) $state > 0.004
                        ? IndianCurrency::formatExact((float) $state)
                        : ''),
                TextColumn::make('running_balance')
                    ->label('Running Balance')
                    ->alignRight()
                    ->state(fn (CompanyTransportLedgerEntry $record): mixed => $record->getAttribute('running_balance'))
                    ->formatStateUsing(fn ($state): string => $state === null || $state === ''
                        ? '—'
                        : IndianCurrency::formatExact((float) $state)),
                TextColumn::make('enteredBy.name')
                    ->label('Entered By')
                    ->toggleable(),
                TextColumn::make('entered_by_role')
                    ->label('Role')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('transaction_date')
                    ->label('Date Range')
                    ->form([
                        DatePicker::make('from')->label('From Date')->native(false),
                        DatePicker::make('to')->label('To Date')->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('transaction_date', '>=', $date))
                            ->when($data['to'] ?? null, fn (Builder $q, $date) => $q->whereDate('transaction_date', '<=', $date));
                    }),
                Filter::make('vehicle_number')
                    ->label('Vehicle No.')
                    ->form([
                        TextInput::make('value')->label('Vehicle No.'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = trim((string) ($data['value'] ?? ''));
                        if ($value === '') {
                            return $query;
                        }

                        return $query->where('vehicle_number', 'like', '%'.$value.'%');
                    }),
                Filter::make('order_no')
                    ->label('Order No.')
                    ->form([
                        TextInput::make('value')->label('Order No.'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = trim((string) ($data['value'] ?? ''));
                        if ($value === '') {
                            return $query;
                        }

                        return $query->where('order_no', 'like', '%'.$value.'%');
                    }),
                Filter::make('transport_charge_type')
                    ->label('Transport Type')
                    ->form([
                        Select::make('value')
                            ->label('Transport Type')
                            ->options(TransportChargeType::options()),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if (blank($value)) {
                            return $query;
                        }

                        return $query->where('transport_charge_type', $value);
                    }),
                Filter::make('expense_type')
                    ->label('Expense Type')
                    ->form([
                        Select::make('value')
                            ->label('Expense Type')
                            ->options(CompanyTransportExpenseType::options()),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if (blank($value)) {
                            return $query;
                        }

                        return $query->where('expense_type', $value);
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (CompanyTransportLedgerEntry $record): bool => CompanyTransportLedgerResource::canEdit($record)),
            ])
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('No transport ledger entries')
            ->emptyStateDescription('Transport credits post automatically when a sales order with Company Transport or Transport Charges Extra is dispatched.');
    }
}
