<?php

namespace App\Filament\Resources\WhatsAppOutboundMessages\Tables;

use App\Models\WhatsAppOutboundMessage;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WhatsAppOutboundMessagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Date/Time')
                    ->dateTime('d M Y • h:i A', 'Asia/Kolkata')
                    ->sortable(),
                TextColumn::make('dealer')
                    ->label('Dealer')
                    ->state(fn (WhatsAppOutboundMessage $record): string => $record->dealerName())
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where('payload->dealer_name', 'like', '%'.$search.'%');
                    }),
                TextColumn::make('to_number')
                    ->label('Mobile No.')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('order_no')
                    ->label('Order No.')
                    ->state(fn (WhatsAppOutboundMessage $record): string => $record->orderNo())
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where('payload->order_no', 'like', '%'.$search.'%');
                    }),
                TextColumn::make('bill')
                    ->label('Bill')
                    ->state(fn (WhatsAppOutboundMessage $record): string => $record->billLabel()),
                TextColumn::make('message_type')
                    ->label('Message Type')
                    ->state(fn (WhatsAppOutboundMessage $record): string => $record->messageTypeLabel())
                    ->badge(),
                TextColumn::make('meta_message_id')
                    ->label('Provider Message ID')
                    ->placeholder('—')
                    ->toggleable()
                    ->limit(24),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (WhatsAppOutboundMessage $record): string => $record->statusLabel())
                    ->color(fn (WhatsAppOutboundMessage $record): string => $record->statusColor()),
            ])
            ->filters([
                SelectFilter::make('source_type')
                    ->label('Message Type')
                    ->options([
                        WhatsAppOutboundMessage::SOURCE_BILL => 'Sales Bill',
                        WhatsAppOutboundMessage::SOURCE_COLLECTION => 'Collection Received',
                    ]),
                SelectFilter::make('status')
                    ->options([
                        WhatsAppOutboundMessage::STATUS_PENDING => 'Pending',
                        WhatsAppOutboundMessage::STATUS_SENT => 'Sent',
                        WhatsAppOutboundMessage::STATUS_DELIVERED => 'Delivered',
                        WhatsAppOutboundMessage::STATUS_FAILED => 'Failed',
                    ]),
                Filter::make('created_at')
                    ->label('Date Range')
                    ->form([
                        DatePicker::make('from')->label('From')->native(false),
                        DatePicker::make('until')->label('Until')->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25);
    }
}
