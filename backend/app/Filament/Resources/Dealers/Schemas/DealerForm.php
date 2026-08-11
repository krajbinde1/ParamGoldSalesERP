<?php

namespace App\Filament\Resources\Dealers\Schemas;

use App\Models\Employee;
use App\Support\EmployeeCodeResolver;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
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
                            ->rule('nullable|regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[A-Z0-9]{1}Z[A-Z0-9]{1}$/')
                            ->validationMessages(['regex' => 'Enter a valid GSTIN.'])
                            ->maxLength(15),
                        TextInput::make('pan_no')
                            ->label('PAN Number')
                            ->extraInputAttributes(['class' => 'uppercase'])
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? strtoupper($state) : null)
                            ->rule('nullable|regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/')
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
                        TextInput::make('state')->required()->maxLength(255),
                        TextInput::make('district')->required()->maxLength(255),
                        TextInput::make('taluka')->required()->maxLength(255),
                        TextInput::make('village')->required()->maxLength(255),
                        TextInput::make('pincode')
                            ->rule('nullable|regex:/^[1-9][0-9]{5}$/')
                            ->validationMessages(['regex' => 'Enter a valid 6-digit PIN code.'])
                            ->maxLength(6),
                    ]),
                Section::make('Account and location')
                    ->columns(2)
                    ->schema([
                        TextInput::make('credit_limit')
                            ->prefix('₹')
                            ->numeric()
                            ->minValue(0)
                            ->default(0),
                        TextInput::make('outstanding')
                            ->prefix('₹')
                            ->numeric()
                            ->minValue(0)
                            ->default(0),
                        TextInput::make('latitude')->numeric()->minValue(-90)->maxValue(90),
                        TextInput::make('longitude')->numeric()->minValue(-180)->maxValue(180),
                    ]),
            ]);
    }
}
