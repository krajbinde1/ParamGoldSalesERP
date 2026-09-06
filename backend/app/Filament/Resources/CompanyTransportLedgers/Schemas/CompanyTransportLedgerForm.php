<?php

namespace App\Filament\Resources\CompanyTransportLedgers\Schemas;

use App\Enums\CompanyTransportExpenseType;
use App\Enums\CompanyTransportPaymentMode;
use App\Models\Order;
use App\Models\Vehicle;
use App\Services\Orders\CompanyTransportLedgerService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

class CompanyTransportLedgerForm
{
    public static function configure(Schema $schema): Schema
    {
        $ledger = app(CompanyTransportLedgerService::class);

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
                            ->required()
                            ->live(),
                        TextInput::make('expense_other_description')
                            ->label('Specify Other Expense')
                            ->maxLength(255)
                            ->required()
                            ->visible(fn (Get $get): bool => $get('expense_type') === CompanyTransportExpenseType::Other->value)
                            ->columnSpanFull(),
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
                        DatePicker::make('related_order_date')
                            ->label('Order Date')
                            ->native(false)
                            ->live()
                            ->dehydrated(false)
                            ->helperText('Optional filter to find dispatched orders by sales order date.'),
                        Select::make('order_id')
                            ->label('Related Order (optional)')
                            ->placeholder('None')
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->searchPrompt('Search by vehicle no. or dealer name')
                            ->getSearchResultsUsing(function (string $search, Get $get) use ($ledger): array {
                                $date = $get('related_order_date');
                                $orderDate = $date instanceof \DateTimeInterface
                                    ? $date->format('Y-m-d')
                                    : (filled($date) ? (string) $date : null);

                                $rows = $ledger->searchRelatedOrders(
                                    search: $search,
                                    orderDate: $orderDate,
                                    limit: 50,
                                );

                                return collect($rows)
                                    ->mapWithKeys(fn (array $row): array => [(int) $row['id'] => $row['label']])
                                    ->all();
                            })
                            ->getOptionLabelUsing(function ($value) use ($ledger): ?string {
                                if (blank($value)) {
                                    return null;
                                }

                                $order = Order::query()->with('dealer:id,firm_name')->find((int) $value);

                                return $order ? $ledger->presentRelatedOrder($order)['label'] : null;
                            })
                            ->helperText('Dispatched orders with Company Transport or Transport Charges Extra only.'),
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
