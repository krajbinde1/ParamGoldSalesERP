<?php

namespace App\Filament\Resources\Employees\Schemas;

use App\Enums\UserRole;
use App\Filament\Support\EmployeeSelect;
use App\Models\Employee;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Closure;

class EmployeeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Employee details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('employee_code')
                            ->label('Employee Code')
                            ->placeholder('Generated automatically when saved')
                            ->readOnly()
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('full_name')
                            ->label('Full Name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('mobile')
                            ->label('Mobile Number')
                            ->tel()
                            ->inputMode('numeric')
                            ->live(onBlur: false)
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                $set('login_id', $state);
                            })
                            ->rules(fn (?Employee $record): array => Employee::accountAndTravelRules($record)['mobile'])
                            ->validationMessages([
                                'digits' => 'Enter exactly 10 numeric digits.',
                                'regex' => 'Enter a valid Indian mobile number beginning with 6, 7, 8, or 9.',
                            ])
                            ->minLength(10)
                            ->maxLength(10),
                        TextInput::make('email')
                            ->email()
                            ->rule(fn (?Employee $record) => Employee::uniqueAmongActive('email', $record?->id))
                            ->rule(fn (?Employee $record) => Employee::uniqueAmongActiveUsers('email', $record?->user?->id))
                            ->maxLength(255),
                        TextInput::make('base_location')
                            ->label('Base Location')
                            ->required()
                            ->maxLength(255),
                        Select::make('department')
                            ->options([
                                'Administration' => 'Administration',
                                'Finance' => 'Finance',
                                'HR' => 'HR',
                                'Operations' => 'Operations',
                                'Sales' => 'Sales',
                                'Warehouse' => 'Warehouse',
                            ])
                            ->searchable()
                            ->required(),
                        TextInput::make('designation')
                            ->required()
                            ->maxLength(255),
                        Select::make('reporting_manager_id')
                            ->label('Reporting Manager')
                            ->relationship('reportingManager', 'full_name')
                            ->searchable()
                            ->preload()
                            ->tap(fn (Select $select) => EmployeeSelect::applyRelationshipSelect($select))
                            ->rules([
                                fn (?Employee $record): Closure => function (
                                    string $attribute,
                                    mixed $value,
                                    Closure $fail,
                                ) use ($record): void {
                                    if ($record === null || blank($value)) {
                                        return;
                                    }

                                    if ((int) $value === (int) $record->id) {
                                        $fail('An employee cannot be their own reporting manager.');
                                    }
                                },
                            ]),
                        DatePicker::make('joining_date')
                            ->label('Joining Date')
                            ->native(false)
                            ->required(),
                        Toggle::make('status')
                            ->label('Active')
                            ->default(true)
                            ->inline(false),
                    ]),
                Section::make('Login Access')
                    ->columns(2)
                    ->schema([
                        TextInput::make('login_id')
                            ->label('Login ID')
                            ->helperText('Automatically matches the mobile number.')
                            ->maxLength(32)
                            ->readOnly()
                            ->disabled()
                            ->dehydrated(false),
                        Select::make('role')
                            ->label('Login Role')
                            ->options(UserRole::options())
                            ->default(UserRole::Employee->value)
                            ->required()
                            ->helperText('Controls mobile app permissions and dashboard access.'),
                    ]),
                Section::make('Compensation')
                    ->columns(2)
                    ->schema([
                        TextInput::make('salary')
                            ->prefix('₹')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                        TextInput::make('daily_allowance')
                            ->label('Daily Allowance')
                            ->prefix('₹')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                        Select::make('travel_allowance_type')
                            ->label('Travel Allowance Type')
                            ->options([
                                'per_km' => 'Per KM',
                                'actual_expense' => 'Actual Expense',
                            ])
                            ->default('actual_expense')
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                if ($state === 'per_km') {
                                    $set('company_card_issued', false);
                                    $set('monthly_travel_expense_limit', null);
                                    $set('company_card_last_four', null);
                                }

                                if ($state === 'actual_expense') {
                                    $set('rate_per_km', null);
                                    $set('daily_km_limit', null);
                                    $set('monthly_km_limit', null);
                                }
                            }),
                        TextInput::make('rate_per_km')
                            ->label('Rate Per KM')
                            ->numeric()
                            ->minValue(0)
                            ->dehydrated(fn (Get $get): bool => $get('travel_allowance_type') === 'per_km')
                            ->required(fn (Get $get): bool => $get('travel_allowance_type') === 'per_km')
                            ->visible(fn (Get $get): bool => $get('travel_allowance_type') === 'per_km'),
                        TextInput::make('daily_km_limit')
                            ->label('Daily KM Limit')
                            ->numeric()
                            ->minValue(0)
                            ->dehydrated(fn (Get $get): bool => $get('travel_allowance_type') === 'per_km')
                            ->required(fn (Get $get): bool => $get('travel_allowance_type') === 'per_km')
                            ->visible(fn (Get $get): bool => $get('travel_allowance_type') === 'per_km'),
                        TextInput::make('monthly_km_limit')
                            ->label('Monthly KM Limit')
                            ->numeric()
                            ->minValue(0)
                            ->dehydrated(fn (Get $get): bool => $get('travel_allowance_type') === 'per_km')
                            ->rules([
                                fn (Get $get): Closure => function (
                                    string $attribute,
                                    mixed $value,
                                    Closure $fail,
                                ) use ($get): void {
                                    if ($get('travel_allowance_type') !== 'per_km') {
                                        return;
                                    }

                                    $daily = (float) ($get('daily_km_limit') ?? 0);
                                    $monthly = (float) ($value ?? 0);

                                    if ($monthly < $daily) {
                                        $fail(
                                            'The monthly KM limit must be greater than or equal to daily km limit.',
                                        );
                                    }
                                },
                            ])
                            ->required(fn (Get $get): bool => $get('travel_allowance_type') === 'per_km')
                            ->visible(fn (Get $get): bool => $get('travel_allowance_type') === 'per_km'),
                        Toggle::make('company_card_issued')
                            ->label('Company Card Issued')
                            ->default(false)
                            ->live()
                            ->afterStateUpdated(fn (bool $state, Set $set) => $state ?: $set('company_card_last_four', null))
                            ->dehydrated(fn (Get $get): bool => $get('travel_allowance_type') === 'actual_expense')
                            ->visible(fn (Get $get): bool => $get('travel_allowance_type') === 'actual_expense'),
                        TextInput::make('monthly_travel_expense_limit')
                            ->label('Monthly Travel Expense Limit')
                            ->numeric()
                            ->minValue(0)
                            ->dehydrated(fn (Get $get): bool => $get('travel_allowance_type') === 'actual_expense')
                            ->required(fn (Get $get): bool => $get('travel_allowance_type') === 'actual_expense')
                            ->visible(fn (Get $get): bool => $get('travel_allowance_type') === 'actual_expense'),
                        TextInput::make('company_card_last_four')
                            ->label('Company Card Last Four Digits')
                            ->inputMode('numeric')
                            ->maxLength(4)
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state)
                                ? str_pad(preg_replace('/\D/', '', $state), 4, '0', STR_PAD_LEFT)
                                : null)
                            ->dehydrated(fn (Get $get): bool => $get('travel_allowance_type') === 'actual_expense'
                                && (bool) $get('company_card_issued'))
                            ->rules(fn (Get $get): array => $get('travel_allowance_type') === 'actual_expense'
                                && (bool) $get('company_card_issued')
                                ? ['required', 'regex:/^\d{4}$/']
                                : ['nullable'])
                            ->validationMessages([
                                'regex' => 'Enter exactly four numeric digits for the company card.',
                            ])
                            ->visible(fn (Get $get): bool => $get('travel_allowance_type') === 'actual_expense'
                                && (bool) $get('company_card_issued')),
                    ]),
                Section::make('Identity and bank details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('aadhaar_number')
                            ->label('Aadhaar Number')
                            ->required()
                            ->inputMode('numeric')
                            ->rule(fn (?Employee $record) => Employee::uniqueAmongActive('aadhaar_number', $record?->id))
                            ->rule('regex:/^[2-9][0-9]{11}$/')
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state)
                                ? preg_replace('/\D/', '', $state)
                                : null)
                            ->validationMessages([
                                'regex' => 'Enter a valid 12-digit Aadhaar number.',
                                'unique' => 'This Aadhaar number is already assigned to another employee.',
                            ])
                            ->maxLength(12),
                        TextInput::make('pan_number')
                            ->label('PAN Number')
                            ->required()
                            ->rule(fn (?Employee $record) => Employee::uniqueAmongActive('pan_number', $record?->id))
                            ->extraInputAttributes(['class' => 'uppercase'])
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? strtoupper($state) : null)
                            ->rule('regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/i')
                            ->validationMessages([
                                'regex' => 'Enter a valid PAN.',
                                'unique' => 'This PAN is already assigned to another employee.',
                            ])
                            ->maxLength(10),
                        TextInput::make('bank_name')
                            ->label('Bank Name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('account_number')
                            ->label('Account Number')
                            ->required()
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state)
                                ? trim($state)
                                : null)
                            ->maxLength(30),
                        TextInput::make('ifsc_code')
                            ->label('IFSC Code')
                            ->required()
                            ->extraInputAttributes(['class' => 'uppercase'])
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? strtoupper($state) : null)
                            ->rule('regex:/^[A-Z]{4}0[A-Z0-9]{6}$/')
                            ->validationMessages(['regex' => 'Enter a valid IFSC code.'])
                            ->maxLength(11),
                    ]),
                Section::make('Profile photo')
                    ->schema([
                        FileUpload::make('profile_photo_path')
                            ->label('Profile Photo')
                            ->image()
                            ->imageEditor()
                            ->directory('employees/profile-photos')
                            ->maxSize(2048),
                    ]),
            ]);
    }
}
