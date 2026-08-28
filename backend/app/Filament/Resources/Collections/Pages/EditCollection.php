<?php

namespace App\Filament\Resources\Collections\Pages;

use App\Filament\Resources\Collections\CollectionResource;
use App\Models\Collection;
use App\Models\CollectionAudit;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCollection extends EditRecord
{
    protected static string $resource = CollectionResource::class;

    public static function canAccess(array $parameters = []): bool
    {
        $user = auth()->user();

        return parent::canAccess($parameters) && ($user?->isAdminUser() ?? false);
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        foreach (['receipt_no', 'bank_name', 'transaction_number', 'remarks', 'admin_remark'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] === '') {
                $data[$field] = null;
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Collection $record */
        $old = CollectionAudit::snapshot(
            Collection::query()
                ->with(['dealer:id,firm_name', 'salesEmployee:id,full_name'])
                ->findOrFail($record->getKey())
        );
        $updated = parent::handleRecordUpdate($record, $data);
        CollectionAudit::record($updated, $old, auth()->user());

        return $updated;
    }

    protected function getRedirectUrl(): string
    {
        return CollectionResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
