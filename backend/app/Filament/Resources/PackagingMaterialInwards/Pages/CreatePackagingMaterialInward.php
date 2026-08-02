<?php

namespace App\Filament\Resources\PackagingMaterialInwards\Pages;

use App\Filament\Resources\PackagingMaterialInwards\PackagingMaterialInwardResource;
use App\Models\PackagingMaterial;
use App\Services\Inventory\PackagingMaterialInwardService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreatePackagingMaterialInward extends CreateRecord
{
    protected static string $resource = PackagingMaterialInwardResource::class;

    protected static bool $canCreateAnother = false;

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Create')
            ->disabled(fn (): bool => $this->isCreating)
            ->extraAttributes([
                'wire:loading.attr' => 'disabled',
                'wire:target' => 'create',
            ]);
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            $this->getCancelFormAction(),
        ];
    }

    protected function handleRecordCreation(array $data): Model
    {
        $items = $data['items'] ?? [];
        unset($data['items']);

        foreach ($items as &$item) {
            $item['inward_quantity'] = $item['inward_quantity']
                ?? $item['accepted_quantity']
                ?? $item['received_quantity']
                ?? 0;
            $item['discount_amount'] = $item['discount_amount'] ?? 0;
            $item['freight_amount'] = $item['freight_amount'] ?? 0;
            $item['other_charges'] = $item['other_charges'] ?? 0;
            $item['gst_percentage'] = $item['gst_percentage'] ?? 0;
        }
        unset($item);

        try {
            return app(PackagingMaterialInwardService::class)
                ->createAndPost($data, $items, auth()->user());
        } catch (ValidationException $e) {
            Notification::make()
                ->title(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                ->danger()
                ->send();
            throw $e;
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Packaging Material Inward created successfully.';
    }

    protected function getRedirectUrl(): string
    {
        return PackagingMaterialInwardResource::getUrl('index');
    }

    public function mount(): void
    {
        parent::mount();

        $preselectMaterialId = request()->integer('packaging_material_id') ?: null;
        if (! $preselectMaterialId) {
            return;
        }

        $material = PackagingMaterial::query()->find($preselectMaterialId);

        $this->form->fill([
            'inward_date' => now('Asia/Kolkata')->toDateString(),
            'items' => [
                [
                    'packaging_material_id' => $preselectMaterialId,
                    'inward_quantity' => null,
                    'basic_rate' => (float) ($material?->purchase_rate ?? 0),
                    'discount_amount' => 0,
                    'freight_amount' => 0,
                    'other_charges' => 0,
                    'gst_percentage' => 0,
                    'unit' => $material?->unit,
                    'current_stock_display' => number_format((float) ($material?->current_stock ?? 0), 3, '.', ''),
                    'current_average_rate' => (float) ($material?->average_rate ?? 0),
                    'current_average_rate_display' => number_format((float) ($material?->average_rate ?? 0), 4, '.', ''),
                ],
            ],
        ]);
    }
}
