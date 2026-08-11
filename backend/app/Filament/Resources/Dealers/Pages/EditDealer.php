<?php

namespace App\Filament\Resources\Dealers\Pages;

use App\Filament\Actions\SafeDeleteActions;
use App\Filament\Resources\Dealers\DealerResource;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditDealer extends EditRecord
{
    protected static string $resource = DealerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            SafeDeleteActions::deactivateAction()
                ->authorize(fn (): bool => DealerResource::canEdit($this->getRecord())),
            SafeDeleteActions::deleteAction()
                ->authorize(fn (): bool => DealerResource::canDelete($this->getRecord())),
            ForceDeleteAction::make()
                ->authorize(fn (): bool => auth()->user()?->can('forceDelete', $this->getRecord()) ?? false)
                ->before(function ($action): void {
                    $assessment = app(\App\Services\SafeDelete\SafeDeleteGuard::class)->assess($this->getRecord());
                    if ($assessment->blocked()) {
                        SafeDeleteActions::notifyBlocked($assessment);
                        $action->cancel();
                    }
                }),
            RestoreAction::make()
                ->authorize(fn (): bool => auth()->user()?->can('restore', $this->getRecord()) ?? false),
        ];
    }
}
