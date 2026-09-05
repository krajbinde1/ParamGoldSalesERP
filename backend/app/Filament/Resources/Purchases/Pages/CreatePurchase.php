<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Filament\Resources\Purchases\PurchaseResource;
use App\Services\Inventory\PurchaseService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreatePurchase extends CreateRecord
{
    protected static string $resource = PurchaseResource::class;

    protected static bool $canCreateAnother = false;

    private bool $confirmAfterCreate = false;

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Save as Draft');
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            Action::make('createAndConfirm')
                ->label('Save & Confirm')
                ->color('success')
                ->action('createAndConfirm'),
            $this->getCancelFormAction(),
        ];
    }

    public function createAndConfirm(): void
    {
        $this->confirmAfterCreate = true;
        $this->create();
    }

    protected function handleRecordCreation(array $data): Model
    {
        $items = $data['items'] ?? [];
        unset($data['items']);

        try {
            return app(PurchaseService::class)->create(
                $data,
                is_array($items) ? array_values($items) : [],
                auth()->user(),
                $this->confirmAfterCreate,
            );
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
        return $this->confirmAfterCreate
            ? 'Purchase confirmed and stock updated.'
            : 'Purchase saved as draft.';
    }

    protected function getRedirectUrl(): string
    {
        return PurchaseResource::getUrl('index');
    }
}
