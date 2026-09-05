<?php

namespace App\Filament\Concerns;

use App\Models\User;
use Filament\Support\Exceptions\Halt;
use Illuminate\Validation\ValidationException;

/**
 * Captures Opening Stock fields from Edit so they can be synced without
 * writing virtual keys onto the master row.
 */
trait SyncsMaterialOpeningStockOnEdit
{
    /**
     * @var array{quantity: mixed, value: mixed, date: mixed}|null
     */
    protected ?array $pendingOpeningStock = null;

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $extraUnset
     * @return array<string, mixed>
     */
    protected function extractOpeningStockAndUnset(array $data, array $extraUnset = []): array
    {
        $this->pendingOpeningStock = [
            'quantity' => $data['opening_stock_quantity'] ?? 0,
            'value' => $data['opening_stock_value'] ?? 0,
            'date' => $data['opening_date'] ?? null,
        ];

        foreach (array_merge([
            'opening_stock_quantity',
            'opening_stock_value',
            'opening_date',
            'opening_effective_rate',
            'opening_stock_cases',
            'opening_average_cost',
        ], $extraUnset) as $key) {
            unset($data[$key]);
        }

        return $data;
    }

    /**
     * Must run from `beforeSave` — Filament calls that hook before
     * `mutateFormDataBeforeSave`, so pending values are read from form state.
     *
     * @param  \Closure(array{quantity: mixed, value: mixed, date: mixed}, User): void  $sync
     */
    protected function applyPendingOpeningStock(\Closure $sync): void
    {
        $this->pendingOpeningStock = [
            'quantity' => $this->data['opening_stock_quantity'] ?? 0,
            'value' => $this->data['opening_stock_value'] ?? 0,
            'date' => $this->data['opening_date'] ?? null,
        ];

        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        try {
            $sync($this->pendingOpeningStock, $user);
            $fresh = $this->getRecord()->fresh();

            if ($fresh !== null) {
                $this->record = $fresh;
            }
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $path = str_starts_with((string) $field, 'data.')
                    ? (string) $field
                    : 'data.'.$field;
                $this->addError($path, implode(' ', $messages));
            }

            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }
}
