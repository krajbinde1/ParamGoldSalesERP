<?php

namespace App\Filament\Resources\FinishedProducts\Pages;

use App\Filament\Resources\FinishedProducts\FinishedProductResource;
use App\Filament\Resources\FinishedProducts\Schemas\FinishedProductForm;
use App\Services\Inventory\FinishedProductCreateService;
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
            $data['product_code'],
            $data['product_name'],
            $data['current_finished_stock'],
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
