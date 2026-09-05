<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Filament\Concerns\RedirectsToPreviousPageAfterSave;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\Purchase;
use App\Services\Inventory\PurchaseService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditPurchase extends EditRecord
{
    use RedirectsToPreviousPageAfterSave;

    protected static string $resource = PurchaseResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Purchase $record */
        $record = $this->getRecord();
        $record->loadMissing(['items.rawMaterial', 'items.packagingMaterial']);

        $data['items'] = $record->items->map(fn ($item): array => [
            'raw_material_id' => $item->raw_material_id,
            'packaging_material_id' => $item->packaging_material_id,
            'quantity' => (float) $item->quantity,
            'unit' => $item->unit,
            'purchase_rate' => (float) $item->purchase_rate,
            'taxable_amount' => (float) $item->taxable_amount,
            'gst_percentage' => (string) (int) (float) $item->gst_percentage,
            'gst_amount' => (float) $item->gst_amount,
            'total_amount' => (float) $item->total_amount,
            'allocated_transport_cost' => (float) $item->allocated_transport_cost,
            'effective_unit_rate' => (float) $item->effective_unit_rate,
            'batch_lot_no' => $item->batch_lot_no,
            'remarks' => $item->remarks,
        ])->values()->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $items = $data['items'] ?? [];
        unset($data['items']);

        try {
            return app(PurchaseService::class)->update(
                $record,
                $data,
                is_array($items) ? array_values($items) : [],
                auth()->user(),
            );
        } catch (ValidationException $e) {
            Notification::make()
                ->title(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                ->danger()
                ->send();
            throw $e;
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            Action::make('confirm')
                ->label('Confirm')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (): bool => auth()->user()?->can('confirm', $this->getRecord()) ?? false)
                ->action(function (): void {
                    try {
                        app(PurchaseService::class)->confirm($this->getRecord(), auth()->user());
                        Notification::make()->title('Purchase confirmed and stock updated.')->success()->send();
                        $this->redirect($this->getRedirectUrl());
                    } catch (ValidationException $e) {
                        Notification::make()->title(collect($e->errors())->flatten()->first() ?? $e->getMessage())->danger()->send();
                    }
                }),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Purchase updated.';
    }
}
