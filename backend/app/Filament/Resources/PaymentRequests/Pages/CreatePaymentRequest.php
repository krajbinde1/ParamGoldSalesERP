<?php

namespace App\Filament\Resources\PaymentRequests\Pages;

use App\Actions\PaymentRequests\StorePaymentRequestSupportingDocuments;
use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
use App\Models\PaymentRequest;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Http\UploadedFile;

class CreatePaymentRequest extends CreateRecord
{
    protected static string $resource = PaymentRequestResource::class;

    /** @var list<UploadedFile> */
    private array $pendingSupportingDocuments = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->pendingSupportingDocuments = [];
        $files = $data['supporting_documents'] ?? [];
        unset($data['supporting_documents']);

        if (is_array($files)) {
            foreach ($files as $file) {
                if ($file instanceof UploadedFile) {
                    $this->pendingSupportingDocuments[] = $file;
                }
            }
        }

        $data['request_no'] = PaymentRequest::generateRequestNo();
        $data['status'] = PaymentRequest::STATUS_PENDING_FIRST;
        $data['created_by'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
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

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
