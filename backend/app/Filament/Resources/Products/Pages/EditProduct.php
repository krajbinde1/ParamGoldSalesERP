<?php

namespace App\Filament\Resources\Products\Pages;

use App\Enums\StockTransactionType;
use App\Filament\Actions\SafeDeleteActions;
use App\Filament\Concerns\RedirectsToPreviousPageAfterSave;
use App\Filament\Concerns\SyncsMaterialOpeningStockOnEdit;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\User;
use App\Services\Inventory\FinishedProductCreateService;
use App\Services\Inventory\FinishedProductOpeningStockCalculator;
use App\Services\Inventory\MaterialOpeningStockSyncService;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Validation\ValidationException;

class EditProduct extends EditRecord
{
    use RedirectsToPreviousPageAfterSave;
    use SyncsMaterialOpeningStockOnEdit;

    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            SafeDeleteActions::deactivateAction()
                ->authorize(fn (): bool => ProductResource::canEdit($this->getRecord())),
            SafeDeleteActions::deleteAction()
                ->authorize(fn (): bool => ProductResource::canDelete($this->getRecord())),
            ForceDeleteAction::make()
                ->authorize(fn (): bool => auth()->user()?->can('forceDelete', $this->getRecord()) ?? false)
                ->before(function ($action): void {
                    $assessment = app(\App\Services\SafeDelete\SafeDeleteGuard::class)->assess($this->getRecord());
                    if ($assessment->blocked()) {
                        SafeDeleteActions::notifyBlocked($assessment);
                        $action->cancel();
                    }
                }),
            RestoreAction::make()
                ->authorize(fn (): bool => auth()->user()?->can('restore', $this->getRecord()) ?? false),
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

        $calculator = app(FinishedProductOpeningStockCalculator::class);
        $nosPerCase = $calculator->nosPerCase($record);
        $averageCost = $calculator->averageCostPerNos($record);

        $data['nos_per_case'] = $nosPerCase > 0 ? $nosPerCase : ($data['nos_per_case'] ?? 1);
        $data['opening_stock_cases'] = FinishedProductOpeningStockCalculator::casesFromQty($qty, $nosPerCase);
        $data['opening_stock_quantity'] = $qty;
        $data['opening_average_cost'] = $averageCost ?? ($rate > 0 ? round($rate, 2) : 0);
        $data['opening_stock_value'] = $averageCost !== null
            ? FinishedProductOpeningStockCalculator::openingStockValue($qty, $averageCost)
            : $value;
        $data['opening_date'] = $openingLedger?->transaction_date?->toDateString()
            ?? now('Asia/Kolkata')->toDateString();
        $data['minimum_finished_stock_cases'] = FinishedProductOpeningStockCalculator::casesFromQty(
            (float) $record->minimum_finished_stock,
            $nosPerCase,
        );
        $data['current_finished_stock_cases'] = FinishedProductOpeningStockCalculator::casesFromQty(
            (float) $record->current_finished_stock,
            $nosPerCase,
        );

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $cases = (float) ($this->data['opening_stock_cases'] ?? 0);

        if ($cases > 0 || $this->getRecord()->activeBom()->exists()) {
            $data['manufacturing_enabled'] = true;
        }

        $data['minimum_finished_stock'] = FinishedProductOpeningStockCalculator::openingQtyNos(
            (float) ($data['minimum_finished_stock_cases'] ?? 0),
            (int) ($data['nos_per_case'] ?? $this->getRecord()->nos_per_case ?? 0),
        );
        unset($data['minimum_finished_stock_cases'], $data['current_finished_stock_cases']);

        return $this->extractOpeningStockAndUnset($data, [
            'opening_finished_stock',
            'current_finished_stock',
            'weighted_average_cost',
            'latest_production_cost',
        ]);
    }

    protected function beforeSave(): void
    {
        /** @var Product $record */
        $record = $this->getRecord();
        $calculator = app(FinishedProductOpeningStockCalculator::class);
        $cases = (float) ($this->data['opening_stock_cases'] ?? 0);
        $date = filled($this->data['opening_date'] ?? null) ? (string) $this->data['opening_date'] : null;
        $storedQty = (float) $record->opening_finished_stock;
        $storedCases = FinishedProductOpeningStockCalculator::casesFromQty(
            $storedQty,
            $calculator->nosPerCase($record),
        );
        $hasOpening = app(FinishedProductCreateService::class)->hasOpeningStock($record);
        $casesUnchanged = abs($cases - $storedCases) <= 0.0005;

        if ($hasOpening && $casesUnchanged) {
            $this->data['opening_stock_quantity'] = $storedQty;
            $this->data['opening_stock_value'] = $this->storedOpeningValue($record);
        } else {
            try {
                $resolved = $calculator->resolveForSave($record, $cases, $date);
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    $path = str_starts_with((string) $field, 'data.')
                        ? (string) $field
                        : 'data.'.$field;
                    $this->addError($path, implode(' ', $messages));
                }

                throw (new Halt)->rollBackDatabaseTransaction();
            }

            $this->data['opening_stock_quantity'] = $resolved['quantity'];
            $this->data['opening_stock_value'] = $resolved['value'];
            $this->data['opening_date'] = $resolved['date'];
        }

        $this->applyPendingOpeningStock(function (array $opening, User $user): void {
            app(MaterialOpeningStockSyncService::class)->syncFinishedProduct(
                $this->getRecord(),
                $opening,
                $user,
            );
        });
    }

    private function storedOpeningValue(Product $record): float
    {
        $ledger = $record->stockLedgers()
            ->where('transaction_type', StockTransactionType::OpeningStock)
            ->orderBy('id')
            ->first();

        if ($ledger !== null) {
            return (float) $ledger->transaction_value;
        }

        return round(
            (float) $record->opening_finished_stock * (float) $record->weighted_average_cost,
            2,
        );
    }
}
