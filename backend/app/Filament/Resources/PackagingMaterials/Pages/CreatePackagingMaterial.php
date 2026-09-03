<?php

namespace App\Filament\Resources\PackagingMaterials\Pages;

use App\Filament\Resources\PackagingMaterials\PackagingMaterialResource;
use App\Filament\Resources\PackagingMaterials\Schemas\PackagingMaterialForm;
use App\Services\Inventory\PackagingMaterialCreateService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreatePackagingMaterial extends CreateRecord
{
    protected static string $resource = PackagingMaterialResource::class;

    public function form(Schema $schema): Schema
    {
        // Explicit create schema — Opening Stock is always included (no visibility toggles).
        return PackagingMaterialForm::configureCreate($schema);
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction()->label('Create'),
            $this->getCancelFormAction()->label('Cancel'),
        ];
    }

    /**
     * Stay on the Create Packaging Material page after save (no redirect to view/inward).
     */
    protected function getRedirectUrl(): string
    {
        return PackagingMaterialResource::getUrl('create');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $qty = round((float) ($data['opening_stock_quantity'] ?? 0), 3);
        $rate = round((float) ($data['opening_effective_rate'] ?? 0), 4);
        $opening = [
            'quantity' => $qty,
            'value' => PackagingMaterialForm::openingStockValue($qty, $rate),
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

        $data['category'] = filled($data['category'] ?? null) ? $data['category'] : 'Other';

        try {
            $record = app(PackagingMaterialCreateService::class)->create(
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
                ? 'Packaging material created with opening stock'
                : 'Packaging material created')
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
