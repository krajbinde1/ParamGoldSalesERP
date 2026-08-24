<?php

namespace App\Filament\Resources\OrderEditPermissionRequests\Tables;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\OrderEditPermissionRequest;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OrderEditPermissionRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('order.order_no')
                    ->label('Order No')
                    ->formatStateUsing(fn (?string $state, OrderEditPermissionRequest $record): string => $record->order?->shortOrderNo() ?: '—')
                    ->url(fn (OrderEditPermissionRequest $record): ?string => $record->order
                        ? OrderResource::getUrl('view', ['record' => $record->order])
                        : null)
                    ->weight('semibold')
                    ->searchable(),
                TextColumn::make('order.dealer.firm_name')
                    ->label('Dealer')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('requestedByUser.name')
                    ->label('Requested By')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('reason')
                    ->label('Reason')
                    ->limit(40)
                    ->wrap()
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state, OrderEditPermissionRequest $record): string => $record->displayStatusLabel())
                    ->color(fn (string $state): string => OrderEditPermissionRequest::statusColor($state)),
                TextColumn::make('created_at')
                    ->label('Requested At')
                    ->dateTime('d M Y • h:i A', 'Asia/Kolkata')
                    ->sortable(),
                TextColumn::make('reviewedByUser.name')
                    ->label('Director')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(OrderEditPermissionRequest::STATUS_LABELS),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
