<?php

namespace App\Filament\Resources\PaymentRequests\Tables;

use App\Actions\PaymentRequests\SendPaymentRequestReminder;
use App\Models\PaymentRequest;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

class PaymentRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('request_no')
                    ->label('Request No')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d M Y')
                    ->sortable(),
                TextColumn::make('vendor_name')
                    ->label('Vendor Name')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('vendor_mobile')
                    ->label('Mobile')
                    ->searchable(),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->money('INR')
                    ->sortable(),
                TextColumn::make('remark')
                    ->label('Remark')
                    ->limit(40)
                    ->toggleable()
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('Current Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => PaymentRequest::statusLabel($state))
                    ->color(fn (string $state): string => PaymentRequest::statusColor($state)),
                TextColumn::make('first_approver_name')
                    ->label('First Approver')
                    ->placeholder('—'),
                TextColumn::make('second_approver_name')
                    ->label('Second Approver')
                    ->placeholder('—'),
                TextColumn::make('payment_status')
                    ->label('Payment Status')
                    ->state(fn (PaymentRequest $record): string => $record->paymentStatusLabel())
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Payment Done' => 'success',
                        'Pending Payment' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('reminder_count')
                    ->label('Reminders')
                    ->alignCenter()
                    ->toggleable(),
                TextColumn::make('last_reminded_at')
                    ->label('Last Reminder')
                    ->dateTime('d M Y, h:i A')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('sendReminder')
                    ->label('Send Reminder')
                    ->icon('heroicon-o-bell-alert')
                    ->color('warning')
                    ->visible(fn (PaymentRequest $record): bool => Gate::forUser(auth()->user())->allows('remind', $record))
                    ->requiresConfirmation()
                    ->action(function (PaymentRequest $record): void {
                        app(SendPaymentRequestReminder::class)->executeOne($record, auth()->user());
                        Notification::make()->title('Reminder sent')->success()->send();
                    }),
                ViewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('sendReminderSelected')
                        ->label('Send Reminder')
                        ->icon('heroicon-o-bell-alert')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            if (! Gate::forUser(auth()->user())->allows('remindPending', PaymentRequest::class)) {
                                Notification::make()->title('Not allowed')->danger()->send();

                                return;
                            }

                            $pending = $records->filter(fn (PaymentRequest $r): bool => $r->isAwaitingApproval());
                            if ($pending->isEmpty()) {
                                Notification::make()->title('No pending requests selected')->warning()->send();

                                return;
                            }

                            // Group by status so each approver queue gets its own reminder.
                            foreach ($pending->groupBy('status') as $status => $group) {
                                app(SendPaymentRequestReminder::class)->executeForApproverQueue(
                                    actor: auth()->user(),
                                    status: (string) $status,
                                );
                            }

                            Notification::make()
                                ->title('Reminders sent')
                                ->success()
                                ->send();
                        }),
                ]),
            ]);
    }
}
