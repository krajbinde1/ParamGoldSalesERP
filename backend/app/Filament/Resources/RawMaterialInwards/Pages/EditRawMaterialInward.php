<?php

namespace App\Filament\Resources\RawMaterialInwards\Pages;

use App\Filament\Concerns\RedirectsToPreviousPageAfterSave;
use App\Filament\Resources\RawMaterialInwards\RawMaterialInwardResource;
use App\Filament\Resources\RawMaterialInwards\Schemas\RawMaterialInwardForm;
use App\Models\RawMaterialInward;
use App\Models\RawMaterialInwardItem;
use App\Services\Inventory\RawMaterialInwardService;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditRawMaterialInward extends EditRecord
{
    use RedirectsToPreviousPageAfterSave;

    protected static string $resource = RawMaterialInwardResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        abort_unless(
            auth()->user()?->can('update', $this->getRecord()) ?? false,
            403,
        );
    }

    public function form(Schema $schema): Schema
    {
        return RawMaterialInwardForm::configure($schema, forEdit: true);
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()
                ->url(fn (): string => RawMaterialInwardResource::getUrl('view', ['record' => $this->getRecord()])),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var RawMaterialInward $record */
        $record = $this->getRecord();
        $record->loadMissing(['items.rawMaterial', 'createdBy']);

        $data['created_by_display'] = $record->createdBy?->name ?? '—';
        $data['created_at_display'] = $record->created_at?->timezone('Asia/Kolkata')->format('d M Y H:i') ?? '—';
        $data['posted_at_display'] = $record->posted_at?->timezone('Asia/Kolkata')->format('d M Y H:i') ?? '—';

        $data['items'] = $record->items->map(function (RawMaterialInwardItem $item) use ($record): array {
            $material = $item->rawMaterial;
            $accepted = (float) $item->accepted_quantity;

            // For posted safe edit, show stock/avg as they will be after reverse (pre-repost).
            if ($record->status->value === 'posted' && $material) {
                $stockBefore = round((float) $material->current_stock - $accepted, 3);
                $oldAvg = $item->old_average_rate !== null
                    ? (float) $item->old_average_rate
                    : (float) $material->average_rate;
            } else {
                $stockBefore = (float) ($material?->current_stock ?? 0);
                $oldAvg = (float) ($material?->average_rate ?? 0);
            }

            return [
                'raw_material_id' => $item->raw_material_id,
                'inward_quantity' => $accepted,
                'basic_rate' => (float) $item->basic_rate,
                'discount_amount' => (float) $item->discount_amount,
                'freight_amount' => (float) $item->freight_amount,
                'other_charges' => (float) $item->other_charges,
                'gst_percentage' => (float) $item->gst_percentage,
                'unit' => $item->unit,
                'remarks' => $item->remarks,
                'current_stock_display' => number_format(max(0, $stockBefore), 3, '.', ''),
                'current_average_rate' => $oldAvg,
                'current_average_rate_display' => number_format($oldAvg, 4, '.', ''),
                'total_amount_display' => number_format((float) $item->total_amount, 2, '.', ''),
                'taxable_value_display' => number_format((float) $item->taxable_amount, 2, '.', ''),
                'gst_amount_display' => number_format((float) $item->igst_amount, 2, '.', ''),
                'effective_inventory_value_display' => number_format((float) $item->landed_cost, 2, '.', ''),
                'effective_unit_rate_display' => number_format((float) $item->effective_unit_rate, 4, '.', ''),
            ];
        })->values()->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $items = $data['items'] ?? [];
        unset(
            $data['items'],
            $data['created_by_display'],
            $data['created_at_display'],
            $data['posted_at_display'],
        );

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
            return app(RawMaterialInwardService::class)
                ->update($record, $data, $items, auth()->user());
        } catch (ValidationException $e) {
            Notification::make()
                ->title(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                ->danger()
                ->send();
            throw $e;
        }
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Raw Material Inward updated successfully.';
    }

}
