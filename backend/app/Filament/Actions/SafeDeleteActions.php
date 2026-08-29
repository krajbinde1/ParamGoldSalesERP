<?php

namespace App\Filament\Actions;

use App\Enums\BomStatus;
use App\Models\Bom;
use App\Services\SafeDelete\SafeDeleteAssessment;
use App\Services\SafeDelete\SafeDeleteGuard;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

final class SafeDeleteActions
{
    public static function deleteAction(string $name = 'delete'): DeleteAction
    {
        return DeleteAction::make($name)
            ->before(function (DeleteAction $action, Model $record): void {
                $assessment = app(SafeDeleteGuard::class)->assess($record);

                if ($assessment->blocked()) {
                    self::notifyBlocked($assessment);
                    $action->cancel();
                }
            });
    }

    public static function deleteBulkAction(string $name = 'delete'): DeleteBulkAction
    {
        return DeleteBulkAction::make($name)
            ->using(function (Collection $records): void {
                $deleted = 0;
                $blockedReasons = [];
                $guard = app(SafeDeleteGuard::class);

                foreach ($records as $record) {
                    if (! $record instanceof Model) {
                        continue;
                    }

                    $assessment = $guard->assess($record);

                    if ($assessment->blocked()) {
                        $blockedReasons[] = $assessment->shortMessage();

                        continue;
                    }

                    $record->delete();
                    $deleted++;
                }

                $blocked = count($blockedReasons);
                $reasonBody = $blockedReasons === [] ? '' : implode("\n", array_unique($blockedReasons));

                if ($deleted > 0 && $blocked === 0) {
                    Notification::make()
                        ->success()
                        ->title('Records deleted')
                        ->body("{$deleted} record(s) deleted successfully.")
                        ->send();

                    return;
                }

                if ($deleted > 0 && $blocked > 0) {
                    Notification::make()
                        ->warning()
                        ->title('Partial delete completed')
                        ->body("{$deleted} record(s) deleted successfully.\n{$reasonBody}")
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->danger()
                    ->title('No records deleted')
                    ->body($reasonBody !== '' ? $reasonBody : 'Selected record(s) could not be deleted.')
                    ->persistent()
                    ->send();
            });
    }

    public static function deactivateAction(string $name = 'deactivate'): Action
    {
        return Action::make($name)
            ->label('Deactivate')
            ->icon('heroicon-o-no-symbol')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Deactivate record')
            ->modalDescription('This record will be marked Inactive. Historical transactions will remain intact, but it will no longer be available for new transactions.')
            ->visible(function (Model $record): bool {
                $assessment = app(SafeDeleteGuard::class)->assess($record);

                return $assessment->blocked() && $assessment->supportsDeactivate && self::isCurrentlyActive($record);
            })
            ->action(function (Model $record): void {
                app(SafeDeleteGuard::class)->deactivate($record);

                Notification::make()
                    ->success()
                    ->title('Record deactivated')
                    ->body('The record is now Inactive. Historical data remains available.')
                    ->send();
            });
    }

    public static function notifyBlocked(SafeDeleteAssessment $assessment): void
    {
        Notification::make()
            ->danger()
            ->title('Cannot delete '.$assessment->entityLabel)
            ->body($assessment->message())
            ->persistent()
            ->send();
    }

    public static function isCurrentlyActive(Model $record): bool
    {
        if ($record instanceof Bom) {
            return $record->status === BomStatus::Active;
        }

        if (! array_key_exists('status', $record->getAttributes()) && ! isset($record->status)) {
            return false;
        }

        return (bool) $record->getAttribute('status') === true;
    }
}
