<?php

namespace App\Filament\Resources\Collections\Actions;

use App\Actions\Collections\UpdateCollectionStatus;
use App\Models\Collection;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;

final class EditCollectionStatusAction
{
    public static function make(): Action
    {
        return Action::make('edit')
            ->label('Edit')
            ->modalHeading('Edit Collection Status')
            ->modalSubmitActionLabel('Save')
            ->modalCancelActionLabel('Cancel')
            ->visible(fn (): bool => auth()->user()?->isAdminUser() === true)
            ->fillForm(fn (Collection $record): array => [
                'status' => in_array($record->status, Collection::adminEditableStatuses(), true)
                    ? $record->status
                    : null,
            ])
            ->form(fn (Collection $record): array => [
                Placeholder::make('current_status')
                    ->label('Current Status')
                    ->content($record->statusLabel()),
                Select::make('status')
                    ->label('New Status')
                    ->options(Collection::adminEditableStatusLabels())
                    ->required()
                    ->native(false),
            ])
            ->action(function (Collection $record, array $data): void {
                $previous = $record->status;
                $updated = app(UpdateCollectionStatus::class)->execute(
                    $record,
                    (string) $data['status'],
                    auth()->user(),
                );

                if ($updated->status === $previous) {
                    return;
                }

                Notification::make()
                    ->title('Collection status updated to '.$updated->statusLabel().'.')
                    ->success()
                    ->send();
            });
    }
}
