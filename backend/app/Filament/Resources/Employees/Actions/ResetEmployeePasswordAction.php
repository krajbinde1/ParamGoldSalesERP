<?php

namespace App\Filament\Resources\Employees\Actions;

use App\Actions\Employees\ResetEmployeePassword;
use App\Models\Employee;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Gate;

class ResetEmployeePasswordAction
{
    public static function make(string $name = 'resetPassword'): Action
    {
        return Action::make($name)
            ->label('Reset Password')
            ->icon('heroicon-o-key')
            ->color('warning')
            ->visible(fn (Employee $record): bool => Gate::allows('resetPassword', $record))
            ->modalHeading('Reset Employee Password')
            ->modalDescription('Choose how to reset the mobile login password. Existing sessions will be revoked.')
            ->form([
                Select::make('reset_mode')
                    ->label('Reset Option')
                    ->options([
                        ResetEmployeePassword::MODE_MOBILE_LAST_FOUR => 'Reset to last 4 digits of mobile number',
                        ResetEmployeePassword::MODE_CUSTOM => 'Set a custom temporary password',
                    ])
                    ->required()
                    ->live()
                    ->default(ResetEmployeePassword::MODE_MOBILE_LAST_FOUR),
                TextInput::make('custom_password')
                    ->label('Temporary Password')
                    ->password()
                    ->revealable()
                    ->minLength(4)
                    ->visible(fn (Get $get): bool => $get('reset_mode') === ResetEmployeePassword::MODE_CUSTOM)
                    ->required(fn (Get $get): bool => $get('reset_mode') === ResetEmployeePassword::MODE_CUSTOM),
                TextInput::make('custom_password_confirmation')
                    ->label('Confirm Temporary Password')
                    ->password()
                    ->revealable()
                    ->visible(fn (Get $get): bool => $get('reset_mode') === ResetEmployeePassword::MODE_CUSTOM)
                    ->required(fn (Get $get): bool => $get('reset_mode') === ResetEmployeePassword::MODE_CUSTOM),
            ])
            ->action(function (Employee $record, array $data): void {
                $result = app(ResetEmployeePassword::class)->execute(
                    employee: $record,
                    actor: auth()->user(),
                    mode: $data['reset_mode'],
                    customPassword: $data['custom_password'] ?? null,
                    customPasswordConfirmation: $data['custom_password_confirmation'] ?? null,
                );

                Notification::make()
                    ->success()
                    ->title('Password reset successfully.')
                    ->body(
                        "Login ID: {$result['login_id']}\n\n".
                        "Temporary Password: {$result['temporary_password']}\n\n".
                        'Copy these credentials now. The temporary password will not be shown again.'
                    )
                    ->persistent()
                    ->send();
            });
    }
}
