<?php

namespace App\Filament\Resources\RawMaterials\Pages;

use App\Filament\Resources\RawMaterials\RawMaterialResource;
use App\Filament\Resources\RawMaterials\Schemas\RawMaterialForm;
use App\Services\Inventory\RawMaterialCreateService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateRawMaterial extends CreateRecord
{
    protected static string $resource = RawMaterialResource::class;

    public function form(Schema $schema): Schema
    {
        // Explicit create schema — Opening Stock is always included (no visibility toggles).
        return RawMaterialForm::configureCreate($schema);
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction()->label('Create'),
            $this->getCancelFormAction()->label('Cancel'),
        ];
    }

    /**
     * Stay on the Create Raw Material page after save (no redirect to view/inward).
     */
    protected function getRedirectUrl(): string
    {
        return RawMaterialResource::getUrl('create');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $opening = [
            'quantity' => $data['opening_stock_quantity'] ?? 0,
            'value' => $data['opening_stock_value'] ?? 0,
            'date' => $data['opening_date'] ?? now('Asia/Kolkata')->toDateString(),
        ];

        unset(
            $data['opening_stock_quantity'],
            $data['opening_stock_value'],
            $data['opening_date'],
            $data['opening_effective_rate'],
            // Legacy keys if present from cached Livewire state — never persist.
            $data['opening_quantity'],
            $data['opening_purchase_rate'],
            $data['opening_gst_percentage'],
            $data['opening_freight'],
            $data['opening_other_charges'],
            $data['opening_remarks'],
        );

        $data['category'] = filled($data['category'] ?? null) ? $data['category'] : 'General';

        try {
            $record = app(RawMaterialCreateService::class)->create(
                materialData: $data,
                opening: $opening,
                user: auth()->user(),
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, implode(' ', $messages));
            }

            throw new Halt;
        }

        $qty = (float) ($opening['quantity'] ?? 0);

        Notification::make()
            ->title($qty > 0
                ? 'Raw material created with opening stock'
                : 'Raw material created')
            ->body($qty > 0
                ? 'Opening stock ledger entry posted and inventory updated.'
                : 'Material created without opening stock.')
            ->success()
            ->send();

        return $record;
    }

    protected function getCreatedNotification(): ?Notification
    {
        // Custom notification is sent from handleRecordCreation.
        return null;
    }
}
