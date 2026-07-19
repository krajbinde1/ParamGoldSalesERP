<?php

namespace App\Filament\Resources\Employees\Schemas;

use App\Enums\UserRole;
use App\Models\Employee;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EmployeeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Employee details')
                    ->columns(2)
                    ->schema([
                        ImageEntry::make('profile_photo_path')->label('Profile Photo')->circular(),
                        TextEntry::make('employee_code')->label('Employee Code'),
                        TextEntry::make('full_name')->label('Full Name'),
                        TextEntry::make('mobile')->label('Mobile Number'),
                        TextEntry::make('email')->placeholder('-'),
                        TextEntry::make('department'),
                        TextEntry::make('designation'),
                        TextEntry::make('reportingManager.full_name')->label('Reporting Manager')->placeholder('-'),
                        TextEntry::make('joining_date')->date(),
                        TextEntry::make('base_location')->label('Base Location'),
                        IconEntry::make('status')->label('Active')->boolean(),
                    ]),
                Section::make('Login Access')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('user.login_id')
                            ->label('Login ID')
                            ->placeholder('-'),
                        TextEntry::make('user.role')
                            ->label('Login Role')
                            ->formatStateUsing(fn (?string $state): string => filled($state)
                                ? (UserRole::tryFrom($state)?->label() ?? $state)
                                : '-'),
                        TextEntry::make('user.account_status')
                            ->label('Account Status')
                            ->state(fn (Employee $record): string => $record->user?->accountStatusLabel() ?? 'No Login Account'),
                        TextEntry::make('user.password_reset_at')
                            ->label('Last Password Reset')
                            ->dateTime()
                            ->placeholder('-'),
                    ]),
                Section::make('Compensation')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('salary')->money('INR'),
                        TextEntry::make('daily_allowance')->label('Daily Allowance')->money('INR'),
                        TextEntry::make('travel_allowance_type')
                            ->label('Travel Allowance Type')
                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                'per_km' => 'Per KM',
                                'actual_expense' => 'Actual Expense',
                                default => $state,
                            }),
                        TextEntry::make('rate_per_km')
                            ->label('Rate Per KM')
                            ->money('INR')
                            ->visible(fn (Employee $record): bool => $record->travel_allowance_type === 'per_km'),
                        TextEntry::make('daily_km_limit')
                            ->label('Daily KM Limit')
                            ->visible(fn (Employee $record): bool => $record->travel_allowance_type === 'per_km'),
                        TextEntry::make('monthly_km_limit')
                            ->label('Monthly KM Limit')
                            ->visible(fn (Employee $record): bool => $record->travel_allowance_type === 'per_km'),
                        IconEntry::make('company_card_issued')
                            ->label('Company Card Issued')
                            ->boolean()
                            ->visible(fn (Employee $record): bool => $record->travel_allowance_type === 'actual_expense'),
                        TextEntry::make('monthly_travel_expense_limit')
                            ->label('Monthly Travel Expense Limit')
                            ->money('INR')
                            ->visible(fn (Employee $record): bool => $record->travel_allowance_type === 'actual_expense'),
                        TextEntry::make('company_card_last_four')
                            ->label('Company Card Last Four Digits')
                            ->placeholder('-')
                            ->visible(fn (Employee $record): bool => $record->travel_allowance_type === 'actual_expense' && $record->company_card_issued),
                    ]),
                Section::make('Identity and bank details')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('aadhaar_number')->label('Aadhaar Number'),
                        TextEntry::make('pan_number')->label('PAN Number'),
                        TextEntry::make('bank_name')->label('Bank Name'),
                        TextEntry::make('account_number')->label('Account Number'),
                        TextEntry::make('ifsc_code')->label('IFSC Code'),
                    ]),
            ]);
    }
}
