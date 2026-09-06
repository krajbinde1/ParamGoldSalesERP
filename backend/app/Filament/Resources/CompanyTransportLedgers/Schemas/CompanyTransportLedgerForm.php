<?php

namespace App\Filament\Resources\CompanyTransportLedgers\Schemas;

use App\Enums\CompanyTransportExpenseType;
use App\Enums\CompanyTransportPaymentMode;
use App\Models\Order;
use App\Models\Vehicle;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

class CompanyTransportLedgerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Transport Expense')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('transaction_date')
                            ->label('Date')
                            ->native(false)
                            ->default(fn () => Carbon::now('Asia/Kolkata')->toDateString())
                            ->required(),
                        TextInput::make('amount')
                            ->label('Amount')
                            ->prefix('₹')
                            ->numeric()
                            ->minValue(0.01)
                            ->step(0.01)
                            ->required(),
                        Select::make('expense_type')
                            ->label('Expense Type')
                            ->options(CompanyTransportExpenseType::options())
                            ->required(),
                        Select::make('vehicle_id')
                            ->label('Vehicle No.')
                            ->relationship('vehicle', 'vehicle_number')
                            ->getOptionLabelFromRecordUsing(fn (Vehicle $record): string => $record->displayLabel())
                            ->searchable()
                            ->preload()
                            ->nullable(),
                        TextInput::make('paid_to')
                            ->label('Paid To')
                            ->maxLength(255)
                            ->required(),
                        Select::make('order_id')
                            ->label('Related Order No. (optional)')
                            ->relationship('order', 'order_no')
                            ->getOptionLabelFromRecordUsing(fn (Order $record): string => (string) $record->order_no)
                            ->searchable()
                            ->preload()
                            ->nullable(),
                        Select::make('payment_mode')
                            ->label('Payment Mode')
                            ->options(CompanyTransportPaymentMode::options())
                            ->required(),
                        Textarea::make('remark')
                            ->label('Remark')
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                        FileUpload::make('attachment_path')
                            ->label('Attachment / Bill / Photo')
                            ->directory('company-transport-expenses')
                            ->disk('public')
                            ->acceptedFileTypes([
                                'application/pdf',
                                'image/jpeg',
                                'image/png',
                                'image/webp',
                            ])
                            ->maxSize(10240)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
