<?php

namespace App\Filament\Resources\DealerCreditLimits\Tables;

use App\Models\Dealer;
use App\Services\Dealers\DealerCreditAssessment;
use App\Services\Dealers\DealerCreditExposureService;
use App\Services\Dealers\DealerCreditLimitWriter;
use App\Support\IndianCurrency;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class DealerCreditLimitsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('firm_name')
                    ->label('Dealer')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('assignedEmployee.full_name')
                    ->label('Employee')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('assignedEmployee.reportingManager.full_name')
                    ->label('Manager')
                    ->placeholder('—'),
                TextColumn::make('district')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('current_outstanding')
                    ->label('Current Outstanding')
                    ->state(fn (Dealer $record): float => self::credit($record)->currentOutstanding)
                    ->formatStateUsing(fn ($state): string => IndianCurrency::format((float) $state)),
                TextColumn::make('base_credit_limit')
                    ->label('Base Limit')
                    ->state(fn (Dealer $record): string => self::limitLabel(self::credit($record)->baseLimit)),
                TextColumn::make('temporary_extension')
                    ->label('Temporary Extension')
                    ->state(function (Dealer $record): string {
                        $credit = self::credit($record);

                        if ($credit->extensionExpired) {
                            return 'Expired';
                        }

                        return IndianCurrency::format($credit->extensionAmount);
                    }),
                TextColumn::make('effective_limit')
                    ->label('Effective Limit')
                    ->state(fn (Dealer $record): string => self::limitLabel(self::credit($record)->effectiveLimit)),
                TextColumn::make('available_limit')
                    ->label('Available Limit')
                    ->state(fn (Dealer $record): string => self::limitLabel(self::credit($record)->availableLimit)),
                TextColumn::make('utilization_percent')
                    ->label('Utilization %')
                    ->state(function (Dealer $record): string {
                        $percent = self::credit($record)->utilizationPercent;

                        return $percent === null ? '—' : rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.').'%';
                    }),
                TextColumn::make('extension_expiry')
                    ->label('Extension Expiry')
                    ->state(fn (Dealer $record): string => self::credit($record)->extensionValidUntil
                        ? \Illuminate\Support\Carbon::parse(self::credit($record)->extensionValidUntil)->format('d M Y')
                        : '—'),
                TextColumn::make('credit_status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Dealer $record): string => self::credit($record)->statusLabel)
                    ->color(fn (string $state): string => match ($state) {
                        'Safe' => 'success',
                        'Near Limit' => 'warning',
                        'Limit Reached', 'Order Exceeds Limit' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('creditLimit.updatedBy.name')
                    ->label('Last Updated By')
                    ->placeholder('—'),
                TextColumn::make('creditLimit.updated_at')
                    ->label('Last Updated At')
                    ->dateTime('d M Y H:i')
                    ->placeholder('—'),
            ])
            ->defaultSort('firm_name')
            ->recordActions([
                Action::make('setLimit')
                    ->label('Set Limit')
                    ->visible(fn (Dealer $record): bool => $record->creditLimit?->base_limit === null)
                    ->form(self::baseForm())
                    ->action(function (Dealer $record, array $data): void {
                        self::run(fn () => app(DealerCreditLimitWriter::class)->setBase(
                            $record,
                            auth()->user(),
                            (float) $data['amount'],
                            (string) $data['remark'],
                        ), 'Credit limit set');
                    }),
                Action::make('editLimit')
                    ->label('Edit Limit')
                    ->visible(fn (Dealer $record): bool => $record->creditLimit?->base_limit !== null)
                    ->fillForm(fn (Dealer $record): array => [
                        'amount' => $record->creditLimit?->base_limit,
                    ])
                    ->form(self::baseForm())
                    ->action(function (Dealer $record, array $data): void {
                        self::run(fn () => app(DealerCreditLimitWriter::class)->setBase(
                            $record,
                            auth()->user(),
                            (float) $data['amount'],
                            (string) $data['remark'],
                        ), 'Credit limit updated');
                    }),
                Action::make('extendLimit')
                    ->label('Extend')
                    ->visible(fn (Dealer $record): bool => $record->creditLimit?->base_limit !== null)
                    ->form([
                        TextInput::make('amount')
                            ->label('Extension Amount')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->prefix('₹'),
                        DatePicker::make('valid_until')
                            ->label('Valid Until')
                            ->required()
                            ->native(false)
                            ->minDate(now()->startOfDay())
                            ->displayFormat('d M Y'),
                        Textarea::make('remark')
                            ->label('Remark')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(function (Dealer $record, array $data): void {
                        self::run(fn () => app(DealerCreditLimitWriter::class)->extend(
                            $record,
                            auth()->user(),
                            (float) $data['amount'],
                            (string) $data['valid_until'],
                            (string) $data['remark'],
                        ), 'Temporary extension saved');
                    }),
                Action::make('expireExtension')
                    ->label('Remove Extension')
                    ->color('danger')
                    ->visible(function (Dealer $record): bool {
                        $profile = $record->creditLimit;
                        if ($profile === null) {
                            return false;
                        }

                        return round((float) $profile->extension_amount, 2) > 0
                            && $profile->extension_expired_at === null
                            && ! app(DealerCreditExposureService::class)->extensionIsExpired($profile);
                    })
                    ->form([
                        Textarea::make('remark')
                            ->label('Remark')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(function (Dealer $record, array $data): void {
                        self::run(fn () => app(DealerCreditLimitWriter::class)->expireExtension(
                            $record,
                            auth()->user(),
                            (string) $data['remark'],
                        ), 'Temporary extension removed');
                    }),
                Action::make('history')
                    ->label('History')
                    ->modalHeading(fn (Dealer $record): string => 'Credit limit history — '.$record->firm_name)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(function (Dealer $record) {
                        app(DealerCreditLimitWriter::class)->recordAutomaticExpiry($record);

                        return view('filament.resources.dealer-credit-limits.history', [
                            'audits' => $record->creditLimitAudits()
                                ->with('changedBy:id,name')
                                ->orderBy('id')
                                ->get(),
                        ]);
                    }),
            ]);
    }

    /**
     * @return list<\Filament\Forms\Components\Component>
     */
    private static function baseForm(): array
    {
        return [
            TextInput::make('amount')
                ->label('Base Limit')
                ->numeric()
                ->required()
                ->minValue(0.01)
                ->prefix('₹'),
            Textarea::make('remark')
                ->label('Remark')
                ->required()
                ->rows(3),
        ];
    }

    private static function credit(Dealer $record): DealerCreditAssessment
    {
        return app(DealerCreditExposureService::class)->fromLoadedDealer($record);
    }

    private static function limitLabel(?float $amount): string
    {
        return $amount === null ? 'Not Set' : IndianCurrency::format($amount);
    }

    private static function run(callable $callback, string $success): void
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            Notification::make()
                ->title(collect($exception->errors())->flatten()->first() ?: 'Unable to save credit limit')
                ->danger()
                ->send();

            return;
        }

        Notification::make()->title($success)->success()->send();
    }
}
