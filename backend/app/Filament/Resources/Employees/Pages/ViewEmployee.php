<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\Actions\ReassignDealersAction;
use App\Filament\Resources\Employees\Actions\ResetEmployeePasswordAction;
use App\Filament\Resources\Employees\EmployeeResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewEmployee extends ViewRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            ResetEmployeePasswordAction::make(),
            ReassignDealersAction::make(),
        ];
    }
}
