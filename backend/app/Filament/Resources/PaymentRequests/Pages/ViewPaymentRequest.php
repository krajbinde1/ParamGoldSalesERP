<?php

namespace App\Filament\Resources\PaymentRequests\Pages;

use App\Actions\PaymentRequests\MarkPaymentRequestPaid;
use App\Actions\PaymentRequests\SendPaymentRequestReminder;
use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
use App\Models\PaymentRequest;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Gate;

class ViewPaymentRequest extends ViewRecord
{
    protected static string $resource = PaymentRequestResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var PaymentRequest $record */
        $record = $this->getRecord();

        return $record->request_no;
    }

    protected function getHeaderActions(): array
    {
        /** @var PaymentRequest $record */
        $record = $this->getRecord();

        return [
            Action::make('sendReminder')
                ->label('Send Reminder')
                ->color('warning')
                ->visible(fn (): bool => Gate::forUser(auth()->user())->allows('remind', $record))
                ->authorize(fn (): bool => Gate::forUser(auth()->user())->allows('remind', $record))
                ->requiresConfirmation()
                ->modalHeading('Send Reminder')
                ->modalDescription('Send an FCM reminder to the current approver for this payment request?')
                ->action(function () use ($record): void {
                    app(SendPaymentRequestReminder::class)->executeOne($record, auth()->user());

                    Notification::make()
                        ->title('Reminder sent')
                        ->success()
                        ->send();

                    $this->refreshFormData([
                        'reminder_count',
                        'last_reminded_at',
                        'last_reminded_by',
                    ]);
                }),
            Action::make('sendReminderToApproverQueue')
                ->label('Remind All Pending for Approver')
                ->color('warning')
                ->visible(fn (): bool => Gate::forUser(auth()->user())->allows('remind', $record))
                ->authorize(fn (): bool => Gate::forUser(auth()->user())->allows('remindPending', PaymentRequest::class))
                ->requiresConfirmation()
                ->modalHeading('Remind Approver Queue')
                ->modalDescription('Send one reminder covering all payment requests currently pending with the same approver?')
                ->action(function () use ($record): void {
                    $requests = app(SendPaymentRequestReminder::class)->executeForApproverQueue(
                        actor: auth()->user(),
                        seed: $record,
                    );

                    Notification::make()
                        ->title('Reminder sent for '.$requests->count().' request(s)')
                        ->success()
                        ->send();

                    $this->refreshFormData([
                        'reminder_count',
                        'last_reminded_at',
                        'last_reminded_by',
                    ]);
                }),
            Action::make('markPaymentDone')
                ->label('Mark Payment Done')
                ->color('success')
                ->visible(fn (): bool => Gate::forUser(auth()->user())->allows('markPaid', $record))
                ->authorize(fn (): bool => Gate::forUser(auth()->user())->allows('markPaid', $record))
                ->modalHeading('Mark Payment Done')
                ->modalSubmitActionLabel('Confirm Payment Done')
                ->form([
                    Textarea::make('payment_remark')
                        ->label('Payment Remark')
                        ->rows(3)
                        ->maxLength(2000),
                    FileUpload::make('payment_proof')
                        ->label('Payment Screenshot / Proof')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(10240)
                        ->required()
                        ->storeFiles(false),
                ])
                ->action(function (array $data) use ($record): void {
                    app(MarkPaymentRequestPaid::class)->execute(
                        paymentRequest: $record,
                        actor: auth()->user(),
                        proof: $data['payment_proof'],
                        remark: $data['payment_remark'] ?? null,
                    );

                    Notification::make()
                        ->title('Payment marked as done')
                        ->success()
                        ->send();

                    $this->refreshFormData([
                        'status',
                        'payment_done_by',
                        'payment_done_at',
                        'payment_remark',
                        'payment_proof_path',
                    ]);
                }),
            Action::make('viewProof')
                ->label('View Payment Proof')
                ->icon('heroicon-o-photo')
                ->color('primary')
                ->url(fn (): ?string => $record->paymentProofUrl())
                ->openUrlInNewTab()
                ->visible(fn (): bool => filled($record->payment_proof_path) && filled($record->paymentProofUrl())),
        ];
    }
}
