<?php

namespace App\Filament\Resources\PaymentRequests\Tables;

use App\Actions\PaymentRequests\DeletePaymentRequest;
use App\Actions\PaymentRequests\SendPaymentRequestReminder;
use App\Filament\Support\TodayDateFilter;
use App\Models\PaymentRequest;
use App\Services\PaymentRequests\PaymentRequestApproverResolver;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

class PaymentRequestsTable
{
    public static function configure(Table $table): Table
    {
        $firstName = (string) config('payment_requests.first_approver_name', 'Krishna Rajbinde');
        $secondName = (string) config('payment_requests.second_approver_name', 'Bhagwan Kakde');

        return $table
            ->extraAttributes(['class' => 'pg-payment-requests-table'])
            ->recordActionsColumnLabel('Action')
            ->recordActionsAlignment('end')
            ->modifyQueryUsing(function (Builder $query): Builder {
                return $query
                    ->reorder()
                    ->orderByRaw(
                        'CASE WHEN status IN (?, ?, ?) THEN 0 ELSE 1 END',
                        [
                            PaymentRequest::STATUS_PENDING_FIRST,
                            PaymentRequest::STATUS_PENDING_SECOND,
                            PaymentRequest::STATUS_APPROVED_FOR_PAYMENT,
                        ],
                    )
                    ->orderByDesc('id');
            })
            ->columns([
                TextColumn::make('request_no')
                    ->label('Request No')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold')
                    ->width('8.5rem'),
                TextColumn::make('vendor_name')
                    ->label('Vendor Name')
                    ->searchable()
                    ->limit(28)
                    ->tooltip(fn (PaymentRequest $record): ?string => filled($record->vendor_name) && mb_strlen((string) $record->vendor_name) > 28
                        ? $record->vendor_name
                        : null)
                    ->grow(),
                TextColumn::make('vendor_mobile')
                    ->label('Mobile')
                    ->searchable()
                    ->width('8rem'),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->money('INR')
                    ->sortable()
                    ->alignEnd()
                    ->width('7.5rem'),
                TextColumn::make('remark')
                    ->label('Remark')
                    ->limit(28)
                    ->tooltip(fn (PaymentRequest $record): ?string => filled($record->remark) && mb_strlen((string) $record->remark) > 28
                        ? $record->remark
                        : null)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—')
                    ->width('10rem'),
                TextColumn::make('status')
                    ->label('Current Stage')
                    ->badge()
                    ->formatStateUsing(fn (PaymentRequest $record): string => $record->currentStageLabel())
                    ->color(fn (string $state): string => PaymentRequest::statusColor($state))
                    ->width('9.5rem'),
                TextColumn::make('current_approver')
                    ->label('Current Approver')
                    ->state(fn (PaymentRequest $record): string => $record->currentApproverLabel())
                    ->placeholder('—')
                    ->width('8.5rem'),
                TextColumn::make('first_approval_status')
                    ->label('First Approval')
                    ->state(fn (PaymentRequest $record): string => $record->firstApprovalStatusLabel())
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Approved' => 'success',
                        'Rejected' => 'danger',
                        'Pending' => 'warning',
                        default => 'gray',
                    })
                    ->width('8rem'),
                TextColumn::make('second_approval_status')
                    ->label('Second Approval')
                    ->state(fn (PaymentRequest $record): string => $record->secondApprovalStatusLabel())
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Approved' => 'success',
                        'Rejected' => 'danger',
                        'Pending' => 'warning',
                        default => 'gray',
                    })
                    ->width('8.5rem'),
                TextColumn::make('reminder_count')
                    ->label('Reminders')
                    ->alignCenter()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('5.5rem'),
                TextColumn::make('last_reminded_at')
                    ->label('Last Reminder')
                    ->dateTime('d M Y, h:i A')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('9.5rem'),
                TextColumn::make('payment_status')
                    ->label('Payment Status')
                    ->state(fn (PaymentRequest $record): string => $record->paymentStatusLabel())
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Payment Done' => 'success',
                        'Pending Payment' => 'warning',
                        default => 'gray',
                    })
                    ->width('8.5rem'),
            ])
            ->filters([
                SelectFilter::make('workflow_status')
                    ->label('Status')
                    ->options([
                        'pending_krishna' => "Pending {$firstName} Approval",
                        'pending_bhagwan' => "Pending {$secondName} Approval",
                        'approved_for_payment' => 'Approved for Payment',
                        'rejected' => 'Rejected',
                        'payment_done' => 'Payment Done',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        return match ($value) {
                            'pending_krishna' => $query->where('status', PaymentRequest::STATUS_PENDING_FIRST),
                            'pending_bhagwan' => $query->where('status', PaymentRequest::STATUS_PENDING_SECOND),
                            'pending_my_approval' => app(PaymentRequestApproverResolver::class)
                                ->constrainPendingMyApproval($query, auth()->user()),
                            'approved_for_payment' => $query->where('status', PaymentRequest::STATUS_APPROVED_FOR_PAYMENT),
                            'rejected' => $query->whereIn('status', [
                                PaymentRequest::STATUS_REJECTED_FIRST,
                                PaymentRequest::STATUS_REJECTED_SECOND,
                            ]),
                            'payment_done' => $query->where('status', PaymentRequest::STATUS_PAYMENT_DONE),
                            default => $query,
                        };
                    }),
                TodayDateFilter::make('payment_done_at', 'Payment Date'),
            ])
            ->recordActions([
                Action::make('sendReminder')
                    ->label('Send Reminder')
                    ->icon('heroicon-o-bell-alert')
                    ->color('warning')
                    ->visible(fn (PaymentRequest $record): bool => Gate::forUser(auth()->user())->allows('remind', $record))
                    ->requiresConfirmation()
                    ->action(function (PaymentRequest $record): void {
                        try {
                            app(SendPaymentRequestReminder::class)->executeOne($record, auth()->user());
                            Notification::make()
                                ->title('Reminder Sent Successfully')
                                ->success()
                                ->send();
                        } catch (\Throwable) {
                            Notification::make()
                                ->title('Unable to send reminder. Please try again.')
                                ->danger()
                                ->send();
                        }
                    }),
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (PaymentRequest $record): bool => Gate::forUser(auth()->user())->allows('update', $record)),
                DeleteAction::make()
                    ->label('Delete')
                    ->visible(fn (PaymentRequest $record): bool => Gate::forUser(auth()->user())->allows('delete', $record))
                    ->requiresConfirmation()
                    ->modalHeading('Delete Payment Request')
                    ->modalDescription('This will permanently delete this payment request. This cannot be undone.')
                    ->modalSubmitActionLabel('Delete')
                    ->successNotificationTitle('Payment request deleted')
                    ->using(function (PaymentRequest $record): void {
                        app(DeletePaymentRequest::class)->execute($record, auth()->user());
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('sendReminderSelected')
                        ->label('Remind All Pending for Current Approver')
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

                            foreach ($pending->groupBy('status') as $status => $group) {
                                try {
                                    app(SendPaymentRequestReminder::class)->executeForApproverQueue(
                                        actor: auth()->user(),
                                        status: (string) $status,
                                    );
                                } catch (\Throwable) {
                                    Notification::make()
                                        ->title('Unable to send reminder. Please try again.')
                                        ->danger()
                                        ->send();

                                    return;
                                }
                            }

                            Notification::make()
                                ->title('Reminder Sent Successfully')
                                ->success()
                                ->send();
                        }),
                ]),
            ]);
    }
}
