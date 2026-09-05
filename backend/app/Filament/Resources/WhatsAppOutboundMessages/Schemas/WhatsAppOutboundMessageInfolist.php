<?php

namespace App\Filament\Resources\WhatsAppOutboundMessages\Schemas;

use App\Models\WhatsAppOutboundMessage;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WhatsAppOutboundMessageInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('WhatsApp message')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('payload.dealer_name')
                            ->label('Dealer')
                            ->state(fn (WhatsAppOutboundMessage $record): string => $record->dealerName()),
                        TextEntry::make('to_number')->label('Mobile No.')->placeholder('—'),
                        TextEntry::make('payload.order_no')
                            ->label('Order No.')
                            ->state(fn (WhatsAppOutboundMessage $record): string => $record->orderNo()),
                        TextEntry::make('payload.bill_number')
                            ->label('Bill')
                            ->state(fn (WhatsAppOutboundMessage $record): string => $record->billLabel()),
                        TextEntry::make('created_at')
                            ->label('Date/Time')
                            ->dateTime('d M Y • h:i A', 'Asia/Kolkata'),
                        TextEntry::make('send_kind')
                            ->label('Message Type')
                            ->state(fn (WhatsAppOutboundMessage $record): string => $record->messageTypeLabel()),
                        TextEntry::make('meta_message_id')
                            ->label('Provider Message ID')
                            ->placeholder('—')
                            ->copyable(),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (WhatsAppOutboundMessage $record): string => $record->statusLabel())
                            ->color(fn (WhatsAppOutboundMessage $record): string => $record->statusColor()),
                        TextEntry::make('error')
                            ->label('Error')
                            ->placeholder('—')
                            ->columnSpanFull()
                            ->visible(fn (WhatsAppOutboundMessage $record): bool => filled($record->error)),
                    ]),
            ]);
    }
}
