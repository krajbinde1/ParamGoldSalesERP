<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Actions\SafeDeleteActions;
use App\Filament\Concerns\RedirectsToPreviousPageAfterSave;
use App\Filament\Resources\Products\ProductResource;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    use RedirectsToPreviousPageAfterSave;

    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            SafeDeleteActions::deactivateAction()
                ->authorize(fn (): bool => ProductResource::canEdit($this->getRecord())),
            SafeDeleteActions::deleteAction()
                ->authorize(fn (): bool => ProductResource::canDelete($this->getRecord())),
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
