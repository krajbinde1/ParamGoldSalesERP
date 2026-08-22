<?php

namespace App\Filament\Resources\Dealers\Schemas;

use App\Filament\Resources\Dealers\DealerResource;
use App\Models\Dealer;
use App\Services\Dealers\DealerLedgerService;
use App\Support\IndianCurrency;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Illuminate\Support\HtmlString;

class DealerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Account Summary')
                    ->visible(fn (Dealer $record): bool => auth()->user()?->can('viewLedger', $record) ?? false)
                    ->schema([
                        TextEntry::make('account_summary_card')
                            ->hiddenLabel()
                            ->html()
                            ->state(function (Dealer $record): HtmlString {
                                $summary = app(DealerLedgerService::class)->getAccountSummary($record);
                                $canViewLedger = auth()->user()?->can('viewLedger', $record) ?? false;
                                $ledgerUrl = $canViewLedger
                                    ? DealerResource::getUrl('ledger', ['record' => $record])
                                    : null;

                                return new HtmlString(view('filament.resources.dealers.partials.account-summary', [
                                    'summary' => $summary,
                                    'ledgerUrl' => $ledgerUrl,
                                ])->render());
                            }),
                    ]),
                Section::make('Dealer Details')
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                        'xl' => 3,
                    ])
                    ->schema([
                        TextEntry::make('dealer_code')
                            ->label('Dealer Code')
                            ->weight(FontWeight::SemiBold),
                        TextEntry::make('firm_name')
                            ->label('Firm Name')
                            ->weight(FontWeight::SemiBold),
                        TextEntry::make('owner_name')
                            ->placeholder('-'),
                        TextEntry::make('mobile'),
                        TextEntry::make('email')
                            ->label('Email address')
                            ->placeholder('-'),
                        TextEntry::make('gst_no')
                            ->label('GSTIN')
                            ->placeholder('-'),
                        TextEntry::make('pan_no')
                            ->label('PAN Number')
                            ->placeholder('-'),
                        TextEntry::make('fertilizer_license_no')
                            ->label('Fertilizer License Number')
                            ->placeholder('-'),
                        TextEntry::make('dealer_type')
                            ->badge()
                            ->placeholder('-'),
                        TextEntry::make('assignedEmployee.full_name')
                            ->label('Assigned Employee')
                            ->formatStateUsing(fn (Dealer $record): string => $record->assignedEmployee?->assignmentLabel() ?? '-'),
                        TextEntry::make('address')
                            ->columnSpanFull()
                            ->placeholder('-'),
                        TextEntry::make('state'),
                        TextEntry::make('district'),
                        TextEntry::make('taluka'),
                        TextEntry::make('village'),
                        TextEntry::make('pincode')
                            ->placeholder('-'),
                        TextEntry::make('credit_limit')
                            ->label('Credit Limit')
                            ->formatStateUsing(fn ($state): string => IndianCurrency::format((float) $state)),
                        TextEntry::make('opening_balance')
                            ->label('Opening Balance')
                            ->formatStateUsing(fn ($state): string => IndianCurrency::format((float) $state)),
                        TextEntry::make('opening_balance_type')
                            ->label('Opening Balance Type')
                            ->formatStateUsing(fn (?string $state): string => match (strtolower((string) $state)) {
                                'credit' => 'Credit',
                                default => 'Debit',
                            }),
                        TextEntry::make('opening_balance_date')
                            ->label('As On Date')
                            ->date('d M Y')
                            ->placeholder('-'),
                        TextEntry::make('latitude')
                            ->numeric()
                            ->placeholder('-'),
                        TextEntry::make('longitude')
                            ->numeric()
                            ->placeholder('-'),
                        IconEntry::make('status')
                            ->boolean(),
                        TextEntry::make('created_at')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('updated_at')
                            ->dateTime()
                            ->placeholder('-'),
                    ]),
            ]);
    }
}
