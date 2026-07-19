<?php

namespace App\Filament\Support;

use App\Models\Employee;
use Filament\Forms\Components\Select;
use Filament\Tables\Filters\SelectFilter;

final class EmployeeSelect
{
    public static function applyRelationshipSelect(Select $select): Select
    {
        return $select
            ->getOptionLabelFromRecordUsing(
                fn (Employee $record): string => $record->displayLabel(),
            )
            ->searchable(['full_name', 'employee_code']);
    }

    public static function applyRelationshipFilter(SelectFilter $filter): SelectFilter
    {
        return $filter
            ->getOptionLabelFromRecordUsing(
                fn (Employee $record): string => $record->displayLabel(),
            )
            ->searchable();
    }
}
