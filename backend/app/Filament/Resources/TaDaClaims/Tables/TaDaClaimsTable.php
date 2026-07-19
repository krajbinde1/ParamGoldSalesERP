<?php

namespace App\Filament\Resources\TaDaClaims\Tables;

use App\Models\TaDaClaim;
use App\Filament\Support\EmployeeSelect;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class TaDaClaimsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('claim_date', 'desc')
            ->columns([
                TextColumn::make('employee.full_name')
                    ->label('Employee')
                    ->formatStateUsing(fn (TaDaClaim $record): string => $record->employee?->displayLabel() ?? '-')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('claim_date')
                    ->label('Claim Date')
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('route')
                    ->label('Route')
                    ->state(fn (TaDaClaim $record): string => $record->routeLabel())
                    ->searchable(['from_location', 'to_location']),
                TextColumn::make('travel_km')
                    ->label('Travel KM')
                    ->numeric(decimalPlaces: 2),
                TextColumn::make('per_km_rate')
                    ->label('Per KM Rate')
                    ->money('INR'),
                TextColumn::make('travel_amount')
                    ->label('Travel Amount')
                    ->money('INR'),
                TextColumn::make('da_amount')
                    ->label('DA Amount')
                    ->money('INR'),
                TextColumn::make('other_expense')
                    ->label('Other Amount')
                    ->money('INR'),
                TextColumn::make('total_amount')
                    ->label('Total Amount')
                    ->money('INR')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => TaDaClaim::statusLabel($state))
                    ->color(fn (string $state): string => match ($state) {
                        TaDaClaim::STATUS_APPROVED => 'success',
                        TaDaClaim::STATUS_PAID => 'info',
                        TaDaClaim::STATUS_REJECTED => 'danger',
                        default => 'warning',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(TaDaClaim::STATUS_LABELS),
                SelectFilter::make('employee_id')
                    ->label('Employee')
                    ->relationship('employee', 'full_name')
                    ->tap(fn (SelectFilter $filter) => EmployeeSelect::applyRelationshipFilter($filter))
                    ->preload(),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('approve')
                    ->label('Approve')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (TaDaClaim $record): bool => $record->canApprove())
                    ->action(fn (TaDaClaim $record) => $record->approve(auth()->id())),
                Action::make('reject')
                    ->label('Reject')
                    ->color('danger')
                    ->visible(fn (TaDaClaim $record): bool => $record->canReject())
                    ->form([
                        Textarea::make('admin_remark')
                            ->label('Admin Remark')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(fn (TaDaClaim $record, array $data) => $record->reject(
                        $data['admin_remark'],
                        auth()->id(),
                    )),
                Action::make('markPaid')
                    ->label('Mark as Paid')
                    ->color('info')
                    ->requiresConfirmation()
                    ->visible(fn (TaDaClaim $record): bool => $record->canMarkPaid())
                    ->action(fn (TaDaClaim $record) => $record->markAsPaid(auth()->id())),
            ]);
    }
}
