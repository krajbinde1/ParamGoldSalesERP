<?php

namespace App\Filament\Resources\PaymentRequests\Pages;

use App\Actions\PaymentRequests\DeletePaymentRequest;
use App\Actions\PaymentRequests\StorePaymentRequestSupportingDocuments;
use App\Filament\Concerns\RedirectsToPreviousPageAfterSave;
use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
use App\Models\PaymentRequest;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Http\UploadedFile;

class EditPaymentRequest extends EditRecord
{
    use RedirectsToPreviousPageAfterSave;

    protected static string $resource = PaymentRequestResource::class;

    /** @var list<UploadedFile> */
    private array $pendingSupportingDocuments = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['supporting_documents']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->pendingSupportingDocuments = [];
        $files = $data['supporting_documents'] ?? [];
        unset($data['supporting_documents'], $data['existing_supporting_documents']);

        if (is_array($files)) {
            foreach ($files as $file) {
                if ($file instanceof UploadedFile) {
                    $this->pendingSupportingDocuments[] = $file;
                }
            }
        }

        return [
            'vendor_name' => $data['vendor_name'],
            'vendor_mobile' => $data['vendor_mobile'],
            'amount' => $data['amount'],
            'remark' => $data['remark'] ?? null,
        ];
    }

    protected function afterSave(): void
    {
        if ($this->pendingSupportingDocuments === []) {
            return;
        }

        /** @var PaymentRequest $record */
        $record = $this->getRecord();

        app(StorePaymentRequestSupportingDocuments::class)->execute(
            paymentRequest: $record,
            actor: auth()->user(),
            files: $this->pendingSupportingDocuments,
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->label('Delete')
                ->requiresConfirmation()
                ->modalHeading('Delete Payment Request')
                ->modalDescription('This will permanently delete this payment request. This cannot be undone.')
                ->modalSubmitActionLabel('Delete')
                ->successNotificationTitle('Payment request deleted')
                ->successRedirectUrl(PaymentRequestResource::getUrl())
                ->using(function (PaymentRequest $record): void {
                    app(DeletePaymentRequest::class)->execute($record, auth()->user());
                }),
        ];
    }
}
