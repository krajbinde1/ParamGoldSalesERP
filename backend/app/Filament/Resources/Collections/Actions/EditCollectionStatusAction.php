<?php

namespace App\Filament\Resources\Collections\Actions;

use App\Actions\Collections\UpdateCollectionStatus;
use App\Models\Collection;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;

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
                'admin_remark' => $record->admin_remark,
            ])
            ->form(fn (Collection $record): array => [
                Placeholder::make('current_status')
                    ->label('Current Status')
                    ->content($record->statusLabel()),
                Select::make('status')
                    ->label('New Status')
                    ->options(Collection::adminEditableStatusLabels())
                    ->required()
                    ->native(false)
                    ->live(),
                Textarea::make('admin_remark')
                    ->label('Remark')
                    ->rows(3)
                    ->visible(fn (Get $get): bool => Collection::statusRequiresRemark((string) ($get('status') ?? '')))
                    ->required(fn (Get $get): bool => Collection::statusRequiresRemark((string) ($get('status') ?? '')))
                    ->dehydrated(fn (Get $get): bool => Collection::statusRequiresRemark((string) ($get('status') ?? ''))),
            ])
            ->action(function (Collection $record, array $data): void {
                $previous = $record->status;
                $updated = app(UpdateCollectionStatus::class)->execute(
                    $record,
                    (string) $data['status'],
                    auth()->user(),
                    isset($data['admin_remark']) ? (string) $data['admin_remark'] : null,
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
