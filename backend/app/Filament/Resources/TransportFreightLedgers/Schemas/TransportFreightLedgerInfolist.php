<?php

namespace App\Filament\Resources\TransportFreightLedgers\Schemas;

use App\Enums\TransportFreightLedgerType;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\TransportFreightLedger;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TransportFreightLedgerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Transport/Freight Charges')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('transaction_date')->label('Purchase Date')->date(),
                        TextEntry::make('transaction_type')
                            ->badge()
                            ->formatStateUsing(fn ($state) => $state instanceof TransportFreightLedgerType ? $state->label() : (string) $state)
                            ->color(fn (TransportFreightLedger $record): string => $record->transaction_type->color()),
                        TextEntry::make('purchase_number')
                            ->label('Purchase No.')
                            ->url(fn (TransportFreightLedger $record): string => PurchaseResource::getUrl('view', ['record' => $record->purchase_id])),
                        TextEntry::make('supplier_name')->label('Supplier')->placeholder('—'),
                        TextEntry::make('transporter_name')->label('Transporter Name')->placeholder('—'),
                        TextEntry::make('transport_invoice_lr_no')->label('Transport Invoice/LR No.')->placeholder('—'),
                        TextEntry::make('amount')->label('Transport Amount')->money('INR'),
                        TextEntry::make('createdBy.name')->label('Recorded By')->placeholder('—'),
                        TextEntry::make('remarks')->label('Remark')->placeholder('—')->columnSpanFull(),
                    ]),
            ]);
    }
}
