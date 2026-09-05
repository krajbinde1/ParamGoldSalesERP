<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\Purchase;
use App\Services\Inventory\PurchaseService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

class ViewPurchase extends ViewRecord
{
    protected static string $resource = PurchaseResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->getRecord()->loadMissing(['items.rawMaterial', 'items.packagingMaterial', 'supplier']);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    protected function getHeaderActions(): array
    {
        /** @var Purchase $record */
        $record = $this->getRecord();

        return [
            EditAction::make()
                ->visible(fn (): bool => PurchaseResource::canSeeEditAction($record)),
            Action::make('confirm')
                ->label('Confirm')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Confirm purchase?')
                ->modalDescription('Stock will be added to the selected material masters.')
                ->visible(fn (): bool => auth()->user()?->can('confirm', $record) ?? false)
                ->action(function () use ($record): void {
                    try {
                        app(PurchaseService::class)->confirm($record, auth()->user());
                        Notification::make()->title('Purchase confirmed and stock updated.')->success()->send();
                        $this->redirect(PurchaseResource::getUrl('view', ['record' => $record]));
                    } catch (ValidationException $e) {
                        Notification::make()->title(collect($e->errors())->flatten()->first() ?? $e->getMessage())->danger()->send();
                    }
                }),
            Action::make('cancel')
                ->label('Cancel Purchase')
                ->color('danger')
                ->requiresConfirmation()
                ->form([
                    Textarea::make('cancellation_reason')->label('Reason')->required(),
                ])
                ->visible(fn (): bool => auth()->user()?->can('cancel', $record) ?? false)
                ->action(function (array $data) use ($record): void {
                    try {
                        app(PurchaseService::class)->cancel($record, auth()->user(), $data['cancellation_reason'] ?? null);
                        Notification::make()->title('Purchase cancelled.')->success()->send();
                        $this->redirect(PurchaseResource::getUrl('view', ['record' => $record]));
                    } catch (ValidationException $e) {
                        Notification::make()->title(collect($e->errors())->flatten()->first() ?? $e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
