<?php

namespace App\Filament\Resources\Attendances\Tables;

use App\Filament\Resources\Attendances\AttendanceResource;
use App\Models\Attendance;
use App\Support\AttendanceCalendar;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EmployeeMonthlyDetailsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('attendance_date')
                    ->label('Attendance Date')
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('punch_in_time')
                    ->label('Punch In Time')
                    ->formatStateUsing(fn (Attendance $record): string => $record->punchInAt()?->timezone(AttendanceCalendar::TIMEZONE)->format('h:i A') ?? '-')
                    ->placeholder('-'),
                TextColumn::make('punch_out_time')
                    ->label('Punch Out Time')
                    ->formatStateUsing(fn (Attendance $record): string => $record->punchOutAt()?->timezone(AttendanceCalendar::TIMEZONE)->format('h:i A') ?? '-')
                    ->placeholder('-'),
                TextColumn::make('working_hours')
                    ->label('Working Hours')
                    ->placeholder('-'),
                TextColumn::make('attendance_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Present' => 'success',
                        'Absent' => 'danger',
                        'Half Day' => 'warning',
                        'Leave' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('approval_status')
                    ->label('Approval Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Approved' => 'success',
                        'Rejected' => 'danger',
                        default => 'warning',
                    }),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn (Attendance $record): string => AttendanceResource::getUrl('view', ['record' => $record])),
            ])
            ->defaultSort('attendance_date')
            ->paginated([10, 25, 50]);
    }
}
