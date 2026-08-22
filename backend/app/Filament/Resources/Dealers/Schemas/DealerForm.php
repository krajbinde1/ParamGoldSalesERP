<?php

namespace App\Filament\Resources\Dealers\Schemas;

use App\Models\Employee;
use App\Support\EmployeeCodeResolver;
use App\Support\MaharashtraGeography;
use App\Rules\MaharashtraTalukaRule;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class DealerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Dealer details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('dealer_code')
                            ->label('Dealer Code')
                            ->placeholder('Generated automatically when saved')
                            ->readOnly()
                            ->dehydrated(false),
                        TextInput::make('firm_name')
                            ->label('Firm Name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('owner_name')
                            ->label('Owner Name')
                            ->maxLength(255),
                        Select::make('dealer_type')
                            ->options([
                                'Distributor' => 'Distributor',
                                'Retailer' => 'Retailer',
                                'Wholesaler' => 'Wholesaler',
                            ])
                            ->default('Retailer'),
                        Toggle::make('status')
                            ->label('Active')
                            ->default(true)
                            ->inline(false),
                        Select::make('assigned_employee_id')
                            ->label('Assigned Employee')
                            ->relationship(
                                'assignedEmployee',
                                'full_name',
                                fn (Builder $query): Builder => EmployeeCodeResolver::scopeAssignableEmployees($query),
                            )
                            ->getOptionLabelFromRecordUsing(
                                fn (Employee $record): string => $record->assignmentLabel(),
                            )
                            ->searchable(['full_name', 'employee_code'])
                            ->preload()
                            ->required(),
                    ]),
                Section::make('Contact and compliance')
                    ->columns(2)
                    ->schema([
                        TextInput::make('mobile')
                            ->tel()
                            ->required()
                            ->rule('regex:/^[6-9][0-9]{9}$/')
                            ->validationMessages(['regex' => 'Enter a valid 10-digit Indian mobile number.'])
                            ->maxLength(10),
                        TextInput::make('email')
                            ->label('Email Address')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('gst_no')
                            ->label('GST Number')
                            ->extraInputAttributes(['class' => 'uppercase'])
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? strtoupper($state) : null)
                            ->rules([
                                'nullable',
                                'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[A-Z0-9]{1}Z[A-Z0-9]{1}$/',
                            ])
                            ->validationMessages(['regex' => 'Enter a valid GSTIN.'])
                            ->maxLength(15),
                        TextInput::make('pan_no')
                            ->label('PAN Number')
                            ->extraInputAttributes(['class' => 'uppercase'])
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? strtoupper($state) : null)
                            ->rules([
                                'nullable',
                                'regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/',
                            ])
                            ->validationMessages(['regex' => 'Enter a valid PAN.'])
                            ->maxLength(10),
                        TextInput::make('fertilizer_license_no')
                            ->label('Fertilizer License Number')
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ]),
                Section::make('Address')
                    ->columns(2)
                    ->schema([
                        Textarea::make('address')
                            ->rows(3)
                            ->columnSpanFull(),
                        Select::make('state')
                            ->options([
                                MaharashtraGeography::STATE_NAME => MaharashtraGeography::STATE_NAME,
                            ])
                            ->default(MaharashtraGeography::STATE_NAME)
                            ->required()
                            ->disabled()
                            ->dehydrated()
                            ->rules(MaharashtraGeography::stateRules()),
                        Select::make('district')
                            ->placeholder('Select District')
                            ->options(fn (Get $get): array => MaharashtraGeography::districtSelectOptions(
                                is_string($get('district')) ? $get('district') : null,
                            ))
                            ->searchable()
                            ->preload()
                            ->live()
                            ->required()
                            ->rules(MaharashtraGeography::districtRules())
                            ->dehydrateStateUsing(
                                fn (?string $state): ?string => MaharashtraGeography::canonicalDistrictName($state) ?? $state,
                            )
                            ->afterStateUpdated(function (?string $state, Set $set, mixed $old): void {
                                if (filled($old) && $old !== $state) {
                                    $set('taluka', null);
                                }
                            }),
                        Select::make('taluka')
                            ->placeholder('Select Taluka')
                            ->options(fn (Get $get): array => MaharashtraGeography::talukaSelectOptions(
                                is_string($get('district')) ? $get('district') : null,
                                is_string($get('taluka')) ? $get('taluka') : null,
                            ))
                            ->searchable()
                            ->preload()
                            ->required()
                            ->disabled(fn (Get $get): bool => blank($get('district')))
                            ->dehydrated()
                            ->rules(fn (Get $get): array => [
                                'required',
                                'string',
                                'max:255',
                                new MaharashtraTalukaRule(is_string($get('district')) ? $get('district') : null),
                            ])
                            ->dehydrateStateUsing(function (?string $state, Get $get): ?string {
                                $district = is_string($get('district')) ? $get('district') : null;

                                return MaharashtraGeography::canonicalTalukaName($district, $state) ?? $state;
                            })
                            ->afterStateHydrated(function (Select $component, mixed $state, Get $get): void {
                                $district = is_string($get('district')) ? $get('district') : null;
                                $canonical = MaharashtraGeography::canonicalTalukaName($district, is_string($state) ? $state : null);
                                if ($canonical !== null && $canonical !== $state) {
                                    $component->state($canonical);
                                }
                            }),
                        TextInput::make('village')->required()->maxLength(255),
                        TextInput::make('pincode')
                            ->rules([
                                'nullable',
                                'regex:/^[1-9][0-9]{5}$/',
                            ])
                            ->validationMessages(['regex' => 'Enter a valid 6-digit PIN code.'])
                            ->maxLength(6),
                    ]),
                Section::make('Account and location')
                    ->columns(2)
                    ->schema([
                        TextInput::make('credit_limit')
                            ->label('Credit Limit')
                            ->prefix('₹')
                            ->numeric()
                            ->minValue(0)
                            ->default(0),
                        TextInput::make('opening_balance')
                            ->label('Opening Balance')
                            ->prefix('₹')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->live()
                            ->disabled(fn (): bool => ! self::canEditOpeningBalance())
                            ->dehydrated()
                            ->helperText('Changing Opening Balance will change the dealer\'s complete ledger and current outstanding.'),
                        Select::make('opening_balance_type')
                            ->label('Opening Balance Type')
                            ->options([
                                'debit' => 'Debit',
                                'credit' => 'Credit',
                            ])
                            ->default('debit')
                            ->required()
                            ->disabled(fn (): bool => ! self::canEditOpeningBalance())
                            ->dehydrated(),
                        DatePicker::make('opening_balance_date')
                            ->label('Opening Balance As On Date')
                            ->native(false)
                            ->displayFormat('d-m-Y')
                            ->placeholder('Select as on date')
                            ->required(fn (Get $get): bool => (float) ($get('opening_balance') ?? 0) > 0)
                            ->disabled(fn (): bool => ! self::canEditOpeningBalance())
                            ->dehydrated()
                            ->helperText('Changing Opening Balance will change the dealer\'s complete ledger and current outstanding.'),
                        TextInput::make('latitude')->numeric()->minValue(-90)->maxValue(90),
                        TextInput::make('longitude')->numeric()->minValue(-180)->maxValue(180),
                    ]),
            ]);
    }

    private static function canEditOpeningBalance(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->usesAdminDirectorDashboard() || $user->isAdminUser());
    }
}
