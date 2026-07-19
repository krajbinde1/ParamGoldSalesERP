<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Employee;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateEmployee extends CreateRecord
{
    protected static string $resource = EmployeeResource::class;

    private ?string $generatedLoginId = null;

    private ?string $generatedTemporaryPassword = null;

    protected function getRedirectUrl(): string
    {
        return EmployeeResource::getUrl('index');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return Employee::normalizeCreationData($data);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $result = app(CreateEmployeeWithUserAccount::class)->execute($data);
        $this->generatedLoginId = $result->loginId;
        $this->generatedTemporaryPassword = $result->temporaryPassword;

        return $result->employee;
    }

    public function create(bool $another = false): void
    {
        try {
            parent::create($another);
        } catch (ValidationException $exception) {
            $this->sendFailureNotification(
                collect($exception->errors())->flatten()->first()
                    ?? 'Please review the highlighted fields and try again.',
            );

            throw $exception;
        } catch (Throwable $exception) {
            $this->sendFailureNotification(
                $exception->getMessage() !== ''
                    ? $exception->getMessage()
                    : 'Something went wrong while saving the employee.',
            );

            throw $exception;
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Employee created successfully';
    }

    protected function afterCreate(): void
    {
        Notification::make()
            ->success()
            ->title('Login credentials generated')
            ->body(
                "Employee Code: {$this->record->employee_code}\n\n".
                "Login ID: {$this->generatedLoginId}\n\n".
                "Temporary password: {$this->generatedTemporaryPassword}\n\n".
                'Copy these credentials now. The temporary password will not be shown again.'
            )
            ->persistent()
            ->send();

        $this->generatedTemporaryPassword = null;
    }

    private function sendFailureNotification(string $body): void
    {
        Notification::make()
            ->danger()
            ->title('Unable to create employee')
            ->body($body)
            ->persistent()
            ->send();
    }
}
