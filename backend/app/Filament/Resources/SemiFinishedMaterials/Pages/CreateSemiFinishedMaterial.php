<?php

namespace App\Filament\Resources\SemiFinishedMaterials\Pages;

use App\Filament\Resources\SemiFinishedMaterials\Schemas\SemiFinishedMaterialForm;
use App\Filament\Resources\SemiFinishedMaterials\SemiFinishedMaterialResource;
use App\Services\Inventory\SemiFinishedMaterialCreateService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateSemiFinishedMaterial extends CreateRecord
{
    protected static string $resource = SemiFinishedMaterialResource::class;

    public function form(Schema $schema): Schema
    {
        // Explicit create schema — Opening Stock is always included (no visibility toggles).
        return SemiFinishedMaterialForm::configureCreate($schema);
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction()->label('Create'),
            $this->getCancelFormAction()->label('Cancel'),
        ];
    }

    /**
     * Stay on the Create Semi-Finished Material page after save (no redirect to view/production).
     */
    protected function getRedirectUrl(): string
    {
        return SemiFinishedMaterialResource::getUrl('create');
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
            // Legacy / stock keys if present from cached Livewire state — never persist.
            $data['opening_quantity'],
            $data['opening_purchase_rate'],
            $data['opening_gst_percentage'],
            $data['opening_freight'],
            $data['opening_other_charges'],
            $data['opening_remarks'],
            $data['current_stock'],
            $data['current_stock_value'],
            $data['average_production_cost'],
            $data['opening_stock'],
        );

        try {
            $record = app(SemiFinishedMaterialCreateService::class)->create(
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
                ? 'Semi-finished material created with opening stock'
                : 'Semi-finished material created')
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
