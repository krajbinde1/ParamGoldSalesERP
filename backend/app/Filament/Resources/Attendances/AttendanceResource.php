<?php

namespace App\Filament\Resources\Attendances;

use App\Filament\Concerns\DeniesOrdersOnlyFilamentUsers;
use App\Filament\Resources\Attendances\Pages\EmployeeAbsentDays;
use App\Filament\Resources\Attendances\Pages\EmployeeHalfDays;
use App\Filament\Resources\Attendances\Pages\EmployeeMonthlyDetails;
use App\Filament\Resources\Attendances\Pages\EmployeePresentDays;
use App\Filament\Resources\Attendances\Pages\ListAttendances;
use App\Filament\Resources\Attendances\Pages\ViewAttendance;
use App\Filament\Resources\Attendances\Schemas\AttendanceForm;
use App\Filament\Resources\Attendances\Schemas\AttendanceInfolist;
use App\Filament\Resources\Attendances\Tables\AttendancesTable;
use App\Models\Attendance;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class AttendanceResource extends Resource
{
    use DeniesOrdersOnlyFilamentUsers;

    protected static ?string $model = Attendance::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Employee Management';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'attendance_date';

    public static function form(Schema $schema): Schema
    {
        return AttendanceForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AttendanceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AttendancesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAttendances::route('/'),
            'view' => ViewAttendance::route('/{record}'),
            'employee-present-days' => EmployeePresentDays::route('/employee/{employee}/present-days'),
            'employee-half-days' => EmployeeHalfDays::route('/employee/{employee}/half-days'),
            'employee-absent-days' => EmployeeAbsentDays::route('/employee/{employee}/absent-days'),
            'employee-monthly-details' => EmployeeMonthlyDetails::route('/employee/{employee}/monthly-details'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
