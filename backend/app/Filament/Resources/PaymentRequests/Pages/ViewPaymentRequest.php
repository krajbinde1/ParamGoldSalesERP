<?php

namespace App\Filament\Resources\PaymentRequests\Pages;

use App\Actions\PaymentRequests\DeletePaymentRequestSupportingDocument;
use App\Actions\PaymentRequests\MarkPaymentRequestPaid;
use App\Actions\PaymentRequests\SendPaymentRequestReminder;
use App\Actions\PaymentRequests\StorePaymentRequestSupportingDocuments;
use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestSupportingDocument;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Http\UploadedFile;
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
            Action::make('addSupportingDocuments')
                ->label('Add Supporting Document')
                ->icon('heroicon-o-paper-clip')
                ->color('gray')
                ->visible(fn (): bool => Gate::forUser(auth()->user())->allows('manageSupportingDocuments', $record))
                ->authorize(fn (): bool => Gate::forUser(auth()->user())->allows('manageSupportingDocuments', $record))
                ->modalHeading('Add Supporting Documents')
                ->modalSubmitActionLabel('Upload')
                ->form([
                    FileUpload::make('supporting_documents')
                        ->label('Supporting Documents')
                        ->multiple()
                        ->appendFiles()
                        ->storeFiles(false)
                        ->required()
                        ->acceptedFileTypes([
                            'application/pdf',
                            'image/jpeg',
                            'image/png',
                        ])
                        ->maxSize(10240)
                        ->helperText('PDF, JPG, JPEG, PNG — max 10 MB each'),
                ])
                ->action(function (array $data) use ($record): void {
                    $files = collect($data['supporting_documents'] ?? [])
                        ->filter(fn ($file): bool => $file instanceof UploadedFile)
                        ->values()
                        ->all();

                    try {
                        app(StorePaymentRequestSupportingDocuments::class)->execute(
                            paymentRequest: $record,
                            actor: auth()->user(),
                            files: $files,
                        );

                        Notification::make()
                            ->title('Supporting document uploaded')
                            ->success()
                            ->send();
                    } catch (\Throwable) {
                        Notification::make()
                            ->title('Unable to upload supporting document')
                            ->danger()
                            ->send();
                    }

                    $this->record->refresh();
                }),
            Action::make('removeSupportingDocument')
                ->label('Remove Supporting Document')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn (): bool => Gate::forUser(auth()->user())->allows('manageSupportingDocuments', $record)
                    && $record->supportingDocuments()->exists())
                ->authorize(fn (): bool => Gate::forUser(auth()->user())->allows('manageSupportingDocuments', $record))
                ->requiresConfirmation()
                ->modalHeading('Remove Supporting Document')
                ->form([
                    Select::make('document_id')
                        ->label('Document')
                        ->required()
                        ->options(
                            fn (): array => $record->supportingDocuments()
                                ->get()
                                ->mapWithKeys(fn (PaymentRequestSupportingDocument $doc): array => [
                                    $doc->id => $doc->original_file_name.' ('.$doc->humanFileSize().')',
                                ])
                                ->all()
                        ),
                ])
                ->action(function (array $data) use ($record): void {
                    $document = PaymentRequestSupportingDocument::query()
                        ->where('payment_request_id', $record->id)
                        ->whereKey($data['document_id'] ?? 0)
                        ->first();

                    if ($document === null) {
                        Notification::make()
                            ->title('Document not found')
                            ->danger()
                            ->send();

                        return;
                    }

                    try {
                        app(DeletePaymentRequestSupportingDocument::class)->execute(
                            document: $document,
                            actor: auth()->user(),
                        );

                        Notification::make()
                            ->title('Supporting document removed')
                            ->success()
                            ->send();
                    } catch (\Throwable) {
                        Notification::make()
                            ->title('Unable to remove supporting document')
                            ->danger()
                            ->send();
                    }

                    $this->record->refresh();
                }),
            Action::make('sendReminder')
                ->label('Send Reminder')
                ->color('warning')
                ->visible(fn (): bool => Gate::forUser(auth()->user())->allows('remind', $record))
                ->authorize(fn (): bool => Gate::forUser(auth()->user())->allows('remind', $record))
                ->requiresConfirmation()
                ->modalHeading('Send Reminder')
                ->modalDescription('Send an FCM reminder to the current Director approver for this payment request?')
                ->successNotificationTitle(null)
                ->action(function () use ($record): void {
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

                    $this->refreshFormData([
                        'reminder_count',
                        'last_reminded_at',
                        'last_reminded_by',
                    ]);
                    $this->record->refresh();
                }),
            Action::make('sendReminderToApproverQueue')
                ->label('Remind All Pending for Approver')
                ->color('warning')
                ->visible(fn (): bool => Gate::forUser(auth()->user())->allows('remind', $record))
                ->authorize(fn (): bool => Gate::forUser(auth()->user())->allows('remindPending', PaymentRequest::class))
                ->requiresConfirmation()
                ->modalHeading('Remind Approver Queue')
                ->modalDescription('Send reminders for all payment requests currently pending with the same approver?')
                ->successNotificationTitle(null)
                ->action(function () use ($record): void {
                    try {
                        $requests = app(SendPaymentRequestReminder::class)->executeForApproverQueue(
                            actor: auth()->user(),
                            seed: $record,
                        );

                        Notification::make()
                            ->title('Reminder Sent Successfully')
                            ->body('Sent for '.$requests->count().' request(s).')
                            ->success()
                            ->send();
                    } catch (\Throwable) {
                        Notification::make()
                            ->title('Unable to send reminder. Please try again.')
                            ->danger()
                            ->send();
                    }

                    $this->refreshFormData([
                        'reminder_count',
                        'last_reminded_at',
                        'last_reminded_by',
                    ]);
                    $this->record->refresh();
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
