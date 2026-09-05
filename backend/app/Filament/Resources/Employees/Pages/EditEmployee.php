<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Actions\Employees\DeleteEmployeeWithUserAccount;
use App\Actions\Employees\UpdateEmployeeWithUserAccount;
use App\Filament\Actions\SafeDeleteActions;
use App\Filament\Concerns\RedirectsToPreviousPageAfterSave;
use App\Filament\Resources\Employees\Actions\ReassignDealersAction;
use App\Filament\Resources\Employees\Actions\ResetEmployeePasswordAction;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Employee;
use App\Services\SafeDelete\SafeDeleteBlockedException;
use App\Services\SafeDelete\SafeDeleteGuard;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditEmployee extends EditRecord
{
    use RedirectsToPreviousPageAfterSave;

    protected static string $resource = EmployeeResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $user = $this->getRecord()->user;
        $role = $user?->role ?? \App\Enums\UserRole::Employee->value;
        $data['role'] = $role instanceof \BackedEnum ? $role->value : $role;
        $data['login_id'] = $data['mobile'] ?? $user?->login_id;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Employee $record */
        return app(UpdateEmployeeWithUserAccount::class)->execute($record, $data);
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            ResetEmployeePasswordAction::make(),
            ReassignDealersAction::make(),
            SafeDeleteActions::deactivateAction()
                ->authorize(fn (): bool => EmployeeResource::canEdit($this->getRecord())),
            DeleteAction::make()
                ->before(function (DeleteAction $action, Employee $record): void {
                    $assessment = app(SafeDeleteGuard::class)->assess($record);

                    if ($assessment->blocked()) {
                        SafeDeleteActions::notifyBlocked($assessment);
                        $action->cancel();
                    }
                })
                ->using(function (Employee $record) {
                    try {
                        return app(DeleteEmployeeWithUserAccount::class)->execute($record);
                    } catch (SafeDeleteBlockedException $exception) {
                        if ($exception->assessment !== null) {
                            SafeDeleteActions::notifyBlocked($exception->assessment);
                        }

                        return null;
                    }
                }),
            ForceDeleteAction::make()
                ->before(function (ForceDeleteAction $action, Employee $record): void {
                    $assessment = app(SafeDeleteGuard::class)->assess($record);

                    if ($assessment->blocked()) {
                        SafeDeleteActions::notifyBlocked($assessment);
                        $action->cancel();
                    }
                })
                ->using(function (Employee $record) {
                    try {
                        return app(DeleteEmployeeWithUserAccount::class)->execute($record, force: true);
                    } catch (SafeDeleteBlockedException $exception) {
                        if ($exception->assessment !== null) {
                            SafeDeleteActions::notifyBlocked($exception->assessment);
                        }

                        return null;
                    }
                }),
            RestoreAction::make(),
        ];
    }
}
