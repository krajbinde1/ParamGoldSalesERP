<?php

namespace App\Enums;

enum FilamentJobRole: string
{
    case ProductionManager = 'Production Manager';
    case ProductionSupervisor = 'Production Supervisor';

    /**
     * @return list<string>
     */
    public static function ordersOnlyAccessValues(): array
    {
        return [
            self::ProductionManager->value,
            self::ProductionSupervisor->value,
        ];
    }

    public static function isOrdersOnlyAccess(?string $jobRole): bool
    {
        return in_array($jobRole, self::ordersOnlyAccessValues(), true);
    }

    public static function isProductionManager(?string $jobRole): bool
    {
        return $jobRole === self::ProductionManager->value;
    }

    public static function isProductionSupervisor(?string $jobRole): bool
    {
        return $jobRole === self::ProductionSupervisor->value;
    }
}
