<?php

namespace App\Filament\Resources\Attendances\Pages;

use App\Actions\Attendance\ApprovePunchOutCorrection;
use App\Actions\Attendance\RejectPunchOutCorrection;
use App\Filament\Resources\Attendances\AttendanceResource;
use App\Models\Attendance;
use App\Models\AttendancePunchOutCorrection;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ViewAttendance extends ViewRecord
{
    protected static string $resource = AttendanceResource::class;

    protected function getHeaderActions(): array
    {
        /** @var Attendance $record */
        $record = $this->getRecord();
        $pending = $record->punchOutCorrections
            ->first(fn (AttendancePunchOutCorrection $row): bool => $row->isActionablePending());

        if ($pending === null) {
            return [];
        }

        $canReview = Gate::forUser(auth()->user())->allows('review', $pending);

        return [
            Action::make('approvePunchOutCorrection')
                ->label('Approve Punch Out Correction')
                ->color('success')
                ->visible(fn (): bool => $canReview)
                ->requiresConfirmation()
                ->modalHeading('Approve Punch Out Correction')
                ->modalDescription('This will set punch out to the requested date/time and recalculate working hours.')
                ->modalSubmitActionLabel('Approve')
                ->form([
                    Textarea::make('remark')->label('Remark')->maxLength(500),
                ])
                ->action(function (array $data) use ($pending): void {
                    try {
                        app(ApprovePunchOutCorrection::class)->execute(
                            $pending,
                            auth()->user(),
                            $data['remark'] ?? null,
                        );
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title(collect($exception->errors())->flatten()->first() ?: 'Unable to approve')
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()->title('Punch out correction approved')->success()->send();
                    $this->refreshFormData(['punch_out_time', 'working_hours', 'attendance_status', 'is_late_punch_out', 'punch_out_correction_status']);
                    $this->record = $this->getRecord()->fresh(['punchOutCorrections.requestedByUser', 'punchOutCorrections.reviewedByUser']);
                }),
            Action::make('rejectPunchOutCorrection')
                ->label('Reject Punch Out Correction')
                ->color('danger')
                ->visible(fn (): bool => $canReview)
                ->requiresConfirmation()
                ->modalHeading('Reject Punch Out Correction')
                ->modalDescription('Attendance will stay unresolved so the employee can submit another correction.')
                ->modalSubmitActionLabel('Reject')
                ->form([
                    Textarea::make('remark')->label('Remark')->maxLength(500),
                ])
                ->action(function (array $data) use ($pending): void {
                    try {
                        app(RejectPunchOutCorrection::class)->execute(
                            $pending,
                            auth()->user(),
                            $data['remark'] ?? null,
                        );
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title(collect($exception->errors())->flatten()->first() ?: 'Unable to reject')
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()->title('Punch out correction rejected')->warning()->send();
                    $this->record = $this->getRecord()->fresh(['punchOutCorrections.requestedByUser', 'punchOutCorrections.reviewedByUser']);
                }),
        ];
    }
}
