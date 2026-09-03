<?php

namespace App\Filament\Resources\PaymentRequests\Pages;

use App\Actions\PaymentRequests\DeletePaymentRequest;
use App\Actions\PaymentRequests\DeletePaymentRequestSupportingDocument;
use App\Actions\PaymentRequests\MarkPaymentRequestPaid;
use App\Actions\PaymentRequests\SendPaymentRequestReminder;
use App\Actions\PaymentRequests\StorePaymentRequestSupportingDocuments;
use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestSupportingDocument;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;

class ViewPaymentRequest extends ViewRecord
{
    protected static string $resource = PaymentRequestResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var PaymentRequest $record */
        $record = $this->getRecord();

        return $record->request_no;
    }

    public function getHeading(): string|Htmlable
    {
        /** @var PaymentRequest $record */
        $record = $this->getRecord();

        return $record->request_no;
    }

    public function getSubheading(): string|Htmlable|null
    {
        /** @var PaymentRequest $record */
        $record = $this->getRecord();

        $statusLabel = e($record->displayStatusLabel());
        $statusColor = match ((string) $record->status) {
            PaymentRequest::STATUS_PENDING_FIRST, PaymentRequest::STATUS_PENDING_SECOND => 'warning',
            PaymentRequest::STATUS_APPROVED_FOR_PAYMENT => 'info',
            PaymentRequest::STATUS_PAYMENT_DONE => 'success',
            PaymentRequest::STATUS_REJECTED_FIRST, PaymentRequest::STATUS_REJECTED_SECOND => 'danger',
            default => 'gray',
        };
        $badgeClass = match ($statusColor) {
            'success' => 'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-400/10 dark:text-success-400 dark:ring-success-400/30',
            'warning' => 'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400 dark:ring-warning-400/30',
            'danger' => 'bg-danger-50 text-danger-700 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-400 dark:ring-danger-400/30',
            'info' => 'bg-info-50 text-info-700 ring-info-600/20 dark:bg-info-400/10 dark:text-info-400 dark:ring-info-400/30',
            'primary' => 'bg-primary-50 text-primary-700 ring-primary-600/20 dark:bg-primary-400/10 dark:text-primary-400 dark:ring-primary-400/30',
            default => 'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-400/10 dark:text-gray-400 dark:ring-gray-400/30',
        };

        return new HtmlString(
            '<span class="inline-flex items-center rounded-md px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset '.$badgeClass.'">'
            .$statusLabel
            .'</span>'
        );
    }

    protected function getHeaderActions(): array
    {
        /** @var PaymentRequest $record */
        $record = $this->getRecord();

        return [
            EditAction::make()
                ->visible(fn (): bool => Gate::forUser(auth()->user())->allows('update', $record)),
            DeleteAction::make()
                ->label('Delete')
                ->visible(fn (): bool => Gate::forUser(auth()->user())->allows('delete', $record))
                ->requiresConfirmation()
                ->modalHeading('Delete Payment Request')
                ->modalDescription('This will permanently delete this payment request. This cannot be undone.')
                ->modalSubmitActionLabel('Delete')
                ->successNotificationTitle('Payment request deleted')
                ->successRedirectUrl(PaymentRequestResource::getUrl())
                ->using(function () use ($record): void {
                    app(DeletePaymentRequest::class)->execute($record, auth()->user());
                }),
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
        ];
    }
}
