<?php

namespace App\Filament\Resources\FinishedProducts\Pages;

use App\Enums\StockTransactionType;
use App\Filament\Actions\SafeDeleteActions;
use App\Filament\Concerns\SyncsMaterialOpeningStockOnEdit;
use App\Filament\Resources\FinishedProducts\FinishedProductResource;
use App\Filament\Resources\FinishedProducts\Schemas\FinishedProductForm;
use App\Models\Product;
use App\Models\User;
use App\Services\Inventory\MaterialOpeningStockSyncService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;

class EditFinishedProduct extends EditRecord
{
    use SyncsMaterialOpeningStockOnEdit;

    protected static string $resource = FinishedProductResource::class;

    public function form(Schema $schema): Schema
    {
        /** @var Product $record */
        $record = $this->getRecord();

        return FinishedProductForm::configureEdit(
            $schema,
            unitLocked: $record->hasFinishedStockTransactions(),
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            SafeDeleteActions::deactivateAction()
                ->authorize(fn (): bool => FinishedProductResource::canEdit($this->getRecord())),
            SafeDeleteActions::deleteAction()
                ->authorize(fn (): bool => FinishedProductResource::canDelete($this->getRecord())),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Product $record */
        $record = $this->getRecord();

        $openingLedger = $record->stockLedgers()
            ->where('transaction_type', StockTransactionType::OpeningStock)
            ->orderBy('id')
            ->first();

        $qty = (float) $record->opening_finished_stock;
        $rate = $openingLedger !== null
            ? (float) $openingLedger->rate
            : (float) $record->weighted_average_cost;
        $value = $openingLedger !== null
            ? (float) $openingLedger->transaction_value
            : round($qty * $rate, 2);

        $data['finished_product_code'] = $record->finishedProduct?->finished_product_code;
        $data['unit'] = $record->finishedProduct?->unit
            ?: ($record->production_unit ?: $record->uom);
        $data['opening_stock_quantity'] = $qty;
        $data['opening_stock_value'] = $value;
        $data['opening_date'] = $openingLedger?->transaction_date?->toDateString()
            ?? ($qty > 0 && $record->created_at !== null
                ? $record->created_at->timezone('Asia/Kolkata')->toDateString()
                : null);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Product $record */
        $record = $this->getRecord();

        unset(
            $data['product_code'],
            $data['product_name'],
            $data['finished_product_code'],
            $data['linked_product_id'],
            $data['mrp'],
            $data['distributor_price'],
            $data['dealer_price'],
            $data['retail_price'],
            $data['gst_percentage'],
            $data['category'],
            $data['uom'],
            $data['nos_per_case'],
        );

        $data = $this->extractOpeningStockAndUnset($data, [
            'opening_finished_stock',
            'current_finished_stock',
            'weighted_average_cost',
            'standard_production_cost',
            'latest_production_cost',
        ]);

        if (array_key_exists('unit', $data)) {
            if ($record->hasFinishedStockTransactions()) {
                unset($data['unit']);
            } else {
                $data['production_unit'] = $data['unit'];
                unset($data['unit']);
            }
        }

        // Keep manufacturing flag on for FG masters edited here.
        $data['manufacturing_enabled'] = true;

        return $data;
    }

    protected function beforeSave(): void
    {
        $this->applyPendingOpeningStock(function (array $opening, User $user): void {
            app(MaterialOpeningStockSyncService::class)->syncFinishedProduct(
                $this->getRecord(),
                $opening,
                $user,
            );
        });
    }

    protected function afterSave(): void
    {
        /** @var Product $record */
        $record = $this->getRecord()->fresh();
        $fp = $record->finishedProduct;

        if ($fp === null) {
            return;
        }

        $fp->fill([
            'unit' => $record->production_unit ?: $record->uom,
            'minimum_stock' => (float) $record->minimum_finished_stock,
            'status' => (bool) $record->status,
            'remarks' => $record->remarks,
        ]);
        $fp->save();
    }
}
