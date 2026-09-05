<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\User;
use App\Services\Inventory\FinishedProductCreateService;
use App\Services\Inventory\FinishedProductOpeningStockCalculator;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Validation\ValidationException;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * @var array{cases: float, date: string|null}|null
     */
    protected ?array $pendingOpeningStockInput = null;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->pendingOpeningStockInput = [
            'cases' => (float) ($data['opening_stock_cases'] ?? 0),
            'date' => filled($data['opening_date'] ?? null) ? (string) $data['opening_date'] : null,
        ];

        if ($this->pendingOpeningStockInput['cases'] > 0) {
            $data['manufacturing_enabled'] = true;
        }

        unset(
            $data['opening_stock_cases'],
            $data['opening_stock_quantity'],
            $data['opening_stock_value'],
            $data['opening_date'],
            $data['opening_average_cost'],
        );

        return $data;
    }

    protected function afterCreate(): void
    {
        $cases = (float) ($this->pendingOpeningStockInput['cases'] ?? 0);

        if ($cases <= 0) {
            return;
        }

        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        /** @var Product $product */
        $product = $this->getRecord();

        try {
            $resolved = app(FinishedProductOpeningStockCalculator::class)
                ->resolveForSave($product, $cases, $this->pendingOpeningStockInput['date'] ?? null);

            app(FinishedProductCreateService::class)->applyOpeningStockToExisting(
                $product,
                [
                    'quantity' => $resolved['quantity'],
                    'value' => $resolved['value'],
                    'date' => $resolved['date'],
                ],
                $user,
            );
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
