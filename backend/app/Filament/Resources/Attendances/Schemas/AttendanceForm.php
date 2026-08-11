<?php

namespace App\Filament\Resources\Attendances\Schemas;

use App\Models\Attendance;
use App\Filament\Support\EmployeeSelect;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class AttendanceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Attendance details')->columns(2)->schema([
                    Select::make('employee_id')->label('Employee')->relationship('employee', 'full_name', fn (Builder $query) => $query->where('status', true))->searchable()->preload()->required()->tap(fn (Select $select) => EmployeeSelect::applyRelationshipSelect($select))
                        ->rules(fn (Get $get, ?Attendance $record): array => [Rule::unique('attendances', 'employee_id')->where('attendance_date', $get('attendance_date'))->ignore($record)]),
                    DatePicker::make('attendance_date')->default(now())->native(false)->required(),
                    Select::make('attendance_status')->label('Attendance Status')->options(Attendance::ATTENDANCE_STATUS_LABELS)->default(\App\Services\Attendance\AttendanceStatusCalculator::STATUS_PUNCHED_IN)->required(),
                    Select::make('approval_status')->options(Attendance::APPROVAL_STATUS_LABELS)->default('Pending')->disabled()->dehydrated(),
                    TimePicker::make('punch_in_time')->label('Punch In Time')->live()->afterStateUpdated(fn (Get $get, Set $set) => self::setWorkingHours($get, $set)),
                    TimePicker::make('punch_out_time')->label('Punch Out Time')->live()->afterStateUpdated(fn (Get $get, Set $set) => self::setWorkingHours($get, $set)),
                    TextInput::make('working_hours')->label('Working Hours')->readOnly()->dehydrated(),
                    Select::make('approved_by')->label('Approved By')->relationship('approver', 'full_name')->searchable()->preload()->tap(fn (Select $select) => EmployeeSelect::applyRelationshipSelect($select)),
                    Textarea::make('remarks')->rows(2)->columnSpanFull(),
                ]),
                Section::make('Punch locations')->columns(2)->schema([
                    TextInput::make('punch_in_location')->label('Punch In Location')->maxLength(255),
                    TextInput::make('punch_out_location')->label('Punch Out Location')->maxLength(255),
                    TextInput::make('punch_in_latitude')->label('Punch In Latitude')->numeric()->minValue(-90)->maxValue(90),
                    TextInput::make('punch_in_longitude')->label('Punch In Longitude')->numeric()->minValue(-180)->maxValue(180),
                    TextInput::make('punch_out_latitude')->label('Punch Out Latitude')->numeric()->minValue(-90)->maxValue(90),
                    TextInput::make('punch_out_longitude')->label('Punch Out Longitude')->numeric()->minValue(-180)->maxValue(180),
                ]),
            ]);
    }

    private static function setWorkingHours(Get $get, Set $set): void
    {
        if (blank($get('punch_in_time')) || blank($get('punch_out_time'))) {
            $set('working_hours', null);

            return;
        }

        $punchIn = Carbon::parse($get('punch_in_time'));
        $punchOut = Carbon::parse($get('punch_out_time'));
        if ($punchOut->lessThan($punchIn)) {
            $punchOut->addDay();
        }
        $minutes = $punchIn->diffInMinutes($punchOut);
        $set('working_hours', sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60));
    }
}
