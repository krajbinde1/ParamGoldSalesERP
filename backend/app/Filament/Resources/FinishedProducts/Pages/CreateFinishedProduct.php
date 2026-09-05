<?php

namespace App\Filament\Resources\FinishedProducts\Pages;

use App\Filament\Resources\FinishedProducts\FinishedProductResource;
use App\Filament\Resources\FinishedProducts\Schemas\FinishedProductForm;
use App\Models\Product;
use App\Services\Inventory\FinishedProductCreateService;
use App\Services\Inventory\FinishedProductOpeningStockCalculator;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateFinishedProduct extends CreateRecord
{
    protected static string $resource = FinishedProductResource::class;

    protected static ?string $title = 'Set Opening Stock';

    protected Width|string|null $maxContentWidth = Width::Full;

    public function form(Schema $schema): Schema
    {
        return FinishedProductForm::configureCreate($schema);
    }

    protected function hasCreateAnother(): bool
    {
        return false;
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction()->label('Save'),
            $this->getCancelFormAction()->label('Cancel'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return FinishedProductResource::getUrl('index');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $linkedProductId = isset($data['linked_product_id']) && filled($data['linked_product_id'])
            ? (int) $data['linked_product_id']
            : null;
        $cases = (float) ($data['opening_stock_cases'] ?? 0);
        $date = isset($data['opening_date']) && filled($data['opening_date'])
            ? (string) $data['opening_date']
            : null;

        $opening = [
            'quantity' => 0,
            'value' => 0,
            'date' => $date,
        ];

        if ($linkedProductId !== null && $cases > 0) {
            $product = Product::query()->find($linkedProductId);
            if ($product === null) {
                $this->addError('data.linked_product_id', 'Selected sales product was not found.');

                throw new Halt;
            }

            try {
                $resolved = app(FinishedProductOpeningStockCalculator::class)
                    ->resolveForSave($product, $cases, $date);
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    $this->addError($field, implode(' ', $messages));
                }

                throw new Halt;
            }

            $opening = [
                'quantity' => $resolved['quantity'],
                'value' => $resolved['value'],
                'date' => $resolved['date'],
            ];
        }

        $nosPerCase = (int) ($data['nos_per_case'] ?? 0);
        if ($nosPerCase <= 0 && $linkedProductId !== null) {
            $linked = Product::query()->find($linkedProductId);
            if ($linked !== null) {
                $nosPerCase = app(FinishedProductOpeningStockCalculator::class)->nosPerCase($linked);
            }
        }

        $data['minimum_finished_stock'] = FinishedProductOpeningStockCalculator::openingQtyNos(
            (float) ($data['minimum_finished_stock_cases'] ?? 0),
            $nosPerCase,
        );

        unset(
            $data['opening_stock_quantity'],
            $data['opening_stock_value'],
            $data['opening_date'],
            $data['opening_stock_cases'],
            $data['opening_average_cost'],
            $data['nos_per_case'],
            $data['opening_effective_rate'],
            $data['product_code'],
            $data['product_name'],
            $data['current_finished_stock'],
            $data['current_finished_stock_cases'],
            $data['minimum_finished_stock_cases'],
            $data['weighted_average_cost'],
            $data['opening_finished_stock'],
        );

        try {
            $record = app(FinishedProductCreateService::class)->create(
                productData: $data,
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
                ? 'Opening stock posted'
                : 'Inventory settings saved')
            ->body($qty > 0
                ? 'Opening stock ledger entry posted and finished inventory updated.'
                : 'Finished goods inventory settings updated without opening stock. Prefer Finished Goods Opening Stock Import for bulk opening balances.')
            ->success()
            ->send();

        return $record;
    }

    protected function getCreatedNotification(): ?Notification
    {
        return null;
    }
}
