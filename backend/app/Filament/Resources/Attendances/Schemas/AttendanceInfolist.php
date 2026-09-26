<?php

namespace App\Filament\Resources\Attendances\Schemas;

use App\Models\AttendancePunchOutCorrection;
use App\Support\AttendanceCalendar;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AttendanceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Attendance details')->columns(3)->schema([
                    TextEntry::make('employee.full_name')->label('Employee'),
                    TextEntry::make('attendance_date')->label('Attendance Date')->date('d M Y'),
                    TextEntry::make('attendance_status')->label('Attendance Status')->badge(),
                    TextEntry::make('punch_in_time')
                        ->label('Punch In Time (IST)')
                        ->formatStateUsing(fn ($record): string => $record->punchInAt()?->timezone(AttendanceCalendar::TIMEZONE)->format('h:i A') ?? '-')
                        ->placeholder('-'),
                    TextEntry::make('punch_out_time')
                        ->label('Punch Out Time (IST)')
                        ->formatStateUsing(fn ($record): string => $record->punchOutAt()?->timezone(AttendanceCalendar::TIMEZONE)->format('h:i A') ?? '-')
                        ->placeholder('-'),
                    TextEntry::make('working_hours')
                        ->label('Working Hours')
                        ->formatStateUsing(fn ($record): string => app(\App\Services\Attendance\AttendanceStatusCalculator::class)->formatWorkingHoursLabel($record))
                        ->placeholder('-'),
                    TextEntry::make('approval_status')->label('Approval Status')->badge(),
                    TextEntry::make('is_late_punch_out')
                        ->label('Late Punch Out')
                        ->badge()
                        ->formatStateUsing(fn ($record): string => $record->is_late_punch_out ? 'Late Punch Out' : 'No')
                        ->color(fn ($record): string => $record->is_late_punch_out ? 'warning' : 'gray'),
                    TextEntry::make('late_punch_out_reason')
                        ->label('Late Punch Out Reason')
                        ->formatStateUsing(fn ($record): string => $record->latePunchOutReasonLabel() ?? '-')
                        ->placeholder('-')
                        ->visible(fn ($record): bool => (bool) $record->is_late_punch_out),
                    TextEntry::make('punch_out_correction_status')
                        ->label('Punch Out Correction')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => match ($state) {
                            'pending' => 'Pending Approval',
                            'approved' => 'Approved',
                            'rejected' => 'Rejected',
                            default => '-',
                        })
                        ->color(fn (?string $state): string => match ($state) {
                            'pending' => 'warning',
                            'approved' => 'success',
                            'rejected' => 'danger',
                            default => 'gray',
                        })
                        ->placeholder('-'),
                    TextEntry::make('approver.full_name')->label('Approved By')->placeholder('-'),
                    TextEntry::make('remarks')->placeholder('-')->columnSpanFull(),
                ]),
                Section::make('Locations')->columns(2)->schema([
                    TextEntry::make('punch_in_location')->placeholder('-'),
                    TextEntry::make('punch_out_location')->placeholder('-'),
                    TextEntry::make('punch_in_latitude')->numeric()->placeholder('-'),
                    TextEntry::make('punch_in_longitude')->numeric()->placeholder('-'),
                    TextEntry::make('punch_out_latitude')->numeric()->placeholder('-'),
                    TextEntry::make('punch_out_longitude')->numeric()->placeholder('-'),
                ]),
                Section::make('Punch Out Corrections')
                    ->visible(fn ($record): bool => $record->punchOutCorrections->isNotEmpty())
                    ->schema([
                        RepeatableEntry::make('punchOutCorrections')
                            ->hiddenLabel()
                            ->schema([
                                TextEntry::make('status')
                                    ->badge()
                                    ->formatStateUsing(fn (?string $state): string => ucfirst((string) $state))
                                    ->color(fn (?string $state): string => match ($state) {
                                        AttendancePunchOutCorrection::STATUS_PENDING => 'warning',
                                        AttendancePunchOutCorrection::STATUS_APPROVED => 'success',
                                        AttendancePunchOutCorrection::STATUS_REJECTED => 'danger',
                                        default => 'gray',
                                    }),
                                TextEntry::make('requested_punch_out_at')
                                    ->label('Requested Punch Out')
                                    ->dateTime('d M Y h:i A', AttendanceCalendar::TIMEZONE),
                                TextEntry::make('reason')
                                    ->label('Reason')
                                    ->formatStateUsing(fn ($state, $record): string => $record instanceof AttendancePunchOutCorrection
                                        ? trim($record->reasonLabel().(filled($record->reason_note) ? ': '.$record->reason_note : ''))
                                        : (string) $state),
                                TextEntry::make('requestedByUser.name')->label('Requested By')->placeholder('-'),
                                TextEntry::make('reviewedByUser.name')->label('Reviewed By')->placeholder('-'),
                                TextEntry::make('reviewed_at')
                                    ->label('Reviewed At')
                                    ->dateTime('d M Y h:i A', AttendanceCalendar::TIMEZONE)
                                    ->placeholder('-'),
                                TextEntry::make('review_remark')->label('Review Remark')->placeholder('-')->columnSpanFull(),
                            ])
                            ->columns(2),
                    ]),
            ]);
    }
}
