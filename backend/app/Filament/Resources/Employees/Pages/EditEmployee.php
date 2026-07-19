<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Actions\Employees\DeleteEmployeeWithUserAccount;
use App\Actions\Employees\UpdateEmployeeWithUserAccount;
use App\Filament\Resources\Employees\Actions\ReassignDealersAction;
use App\Filament\Resources\Employees\Actions\ResetEmployeePasswordAction;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Employee;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditEmployee extends EditRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $user = $this->getRecord()->user;
        $data['role'] = $user?->role ?? \App\Enums\UserRole::Employee->value;
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
            DeleteAction::make()
                ->before(function (Employee $record): void {
                    if ($record->assignedDealers()->exists()) {
                        Notification::make()
                            ->danger()
                            ->title('Cannot delete employee')
                            ->body('This employee has '.$record->assignedDealers()->count()
                                .' assigned dealer(s). Use Reassign Dealers first.')
                            ->send();

                        $this->halt();
                    }
                })
                ->using(fn (Employee $record) => app(DeleteEmployeeWithUserAccount::class)->execute($record)),
            ForceDeleteAction::make()
                ->before(function (Employee $record): void {
                    if ($record->assignedDealers()->exists()) {
                        Notification::make()
                            ->danger()
                            ->title('Cannot delete employee')
                            ->body('This employee has '.$record->assignedDealers()->count()
                                .' assigned dealer(s). Use Reassign Dealers first.')
                            ->send();

                        $this->halt();
                    }
                })
                ->using(fn (Employee $record) => app(DeleteEmployeeWithUserAccount::class)->execute($record, force: true)),
            RestoreAction::make(),
        ];
    }
}
