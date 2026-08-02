<?php

namespace App\Filament\Resources\PackagingMaterialInwards\Pages;

use App\Filament\Resources\PackagingMaterialInwards\PackagingMaterialInwardResource;
use App\Models\PackagingMaterialInward;
use App\Services\Inventory\PackagingMaterialInwardService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Validation\ValidationException;

class ViewPackagingMaterialInward extends ViewRecord
{
    protected static string $resource = PackagingMaterialInwardResource::class;

    protected function getHeaderActions(): array
    {
        /** @var PackagingMaterialInward $record */
        $record = $this->getRecord();

        return [
            Action::make('cancel')
                ->label('Cancel Inward')
                ->color('danger')
                ->requiresConfirmation()
                ->form([
                    Textarea::make('cancellation_reason')->required(),
                ])
                ->visible(fn (): bool => auth()->user()?->can('cancel', $record) ?? false)
                ->action(function (array $data) use ($record): void {
                    $this->runService(
                        fn () => app(PackagingMaterialInwardService::class)->cancel($record, auth()->user(), $data['cancellation_reason'] ?? null),
                        'Inward cancelled',
                    );
                }),
        ];
    }

    private function runService(callable $callback, string $successMessage): void
    {
        try {
            $callback();
            Notification::make()->title($successMessage)->success()->send();
            $this->refreshFormData(['status', 'posted_at']);
            $this->dispatch('$refresh');
        } catch (ValidationException $e) {
            Notification::make()
                ->title(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                ->danger()
                ->send();
        }
    }
}
