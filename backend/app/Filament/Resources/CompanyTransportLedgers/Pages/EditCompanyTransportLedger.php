<?php

namespace App\Filament\Resources\CompanyTransportLedgers\Pages;

use App\Filament\Concerns\RedirectsToPreviousPageAfterSave;
use App\Filament\Resources\CompanyTransportLedgers\CompanyTransportLedgerResource;
use App\Models\CompanyTransportLedgerEntry;
use App\Services\Orders\CompanyTransportLedgerService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCompanyTransportLedger extends EditRecord
{
    use RedirectsToPreviousPageAfterSave;

    protected static string $resource = CompanyTransportLedgerResource::class;

    public function getTitle(): string
    {
        return 'Edit Transport Expense';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var CompanyTransportLedgerEntry $record */
        $record = $this->getRecord();
        $ids = $record->relatedOrders()->pluck('orders.id')->map(fn ($id): int => (int) $id)->all();
        if ($ids === [] && filled($record->order_id)) {
            $ids = [(int) $record->order_id];
        }
        $data['order_ids'] = $ids;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var CompanyTransportLedgerEntry $record */
        return app(CompanyTransportLedgerService::class)->updateExpense($record, auth()->user(), $data);
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
