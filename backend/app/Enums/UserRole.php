<?php

namespace App\Enums;

enum UserRole: string
{
    case Employee = 'employee';
    case Manager = 'manager';
    case ProductionSupervisor = 'production_supervisor';
    case Director = 'director';

    public function label(): string
    {
        return match ($this) {
            self::Employee => 'Employee',
            self::Manager => 'Manager',
            self::ProductionSupervisor => 'Production Supervisor',
            self::Director => 'Director',
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

    /**
     * @return list<string>
     */
    public static function mobileValues(): array
    {
        return array_map(
            static fn (self $role): string => $role->value,
            self::cases(),
        );
    }

    public static function tryFromMixed(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::Employee;
        }

        return self::tryFrom($value) ?? self::Employee;
    }

    public function canUseEmployeeWorkflow(): bool
    {
        return $this === self::Employee;
    }

    public function canApproveOrders(): bool
    {
        return $this === self::Manager;
    }

    public function canDispatchOrders(): bool
    {
        return $this === self::ProductionSupervisor;
    }

    public function canApproveTaDaClaims(): bool
    {
        return $this === self::Manager;
    }

    public function canViewCompanyDashboard(): bool
    {
        return $this === self::Director;
    }

    public function canViewManagerDashboard(): bool
    {
        return $this === self::Manager;
    }

    public function canViewProductionDashboard(): bool
    {
        return $this === self::ProductionSupervisor;
    }

    /**
     * @return list<string>
     */
    public function mobilePermissions(): array
    {
        return match ($this) {
            self::Employee => [
                'attendance', 'route_tracking', 'orders_create', 'orders_view_own',
                'dealer_visits', 'field_activities', 'collections', 'ta_da_claims',
            ],
            self::Manager => [
                'manager_dashboard', 'orders_approve', 'orders_reject', 'orders_view_all',
                'ta_da_approve', 'ta_da_reject', 'ta_da_view_all', 'attendance_view_all',
                'collections_view_all', 'dealer_visits_view_all', 'field_activities_view_all',
            ],
            self::ProductionSupervisor => [
                'production_dashboard', 'orders_dispatch', 'orders_view_production',
            ],
            self::Director => [
                'director_dashboard', 'orders_view_all', 'ta_da_view_all',
                'attendance_view_all', 'collections_view_all', 'dealer_visits_view_all',
                'field_activities_view_all', 'employee_performance_view_all',
            ],
        };
    }
}
