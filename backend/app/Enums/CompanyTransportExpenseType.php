<?php

namespace App\Enums;

enum CompanyTransportExpenseType: string
{
    case Fuel = 'fuel';
    case Toll = 'toll';
    case DriverCharges = 'driver_charges';
    case VehicleMaintenance = 'vehicle_maintenance';
    case FreightPaid = 'freight_paid';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Fuel => 'Fuel',
            self::Toll => 'Toll',
            self::DriverCharges => 'Driver Charges',
            self::VehicleMaintenance => 'Vehicle Maintenance',
            self::FreightPaid => 'Freight Paid',
            self::Other => 'Other',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
