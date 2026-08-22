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
                'dealers_create', 'dealer_ledger_view',
            ],
            self::Manager => [
                'manager_dashboard', 'orders_approve', 'orders_reject', 'orders_view_all',
                'ta_da_approve', 'ta_da_reject', 'ta_da_view_all', 'attendance', 'route_tracking',
                'attendance_view_all', 'collections_view_all', 'dealer_visits_view_all',
                'field_activities_view_all', 'dealers_approve', 'dealer_ledger_view',
            ],
            self::ProductionSupervisor => [
                'production_dashboard', 'orders_dispatch', 'orders_view_production',
                'inventory_view', 'bom_view_active', 'production_create', 'production_complete',
                'production_history_view', 'shortage_report_view', 'stock_report_view',
            ],
            self::Director => [
                'director_dashboard', 'orders_view_all', 'ta_da_view_all',
                'attendance_view_all', 'collections_view_all', 'dealer_visits_view_all',
                'field_activities_view_all', 'employee_performance_view_all',
                'inventory_full_access', 'bom_manage', 'inventory_valuation_view',
                'stock_adjustment', 'production_batch_reverse', 'production_cost_view',
                'production_deviation_approve', 'dealer_ledger_view',
            ],
        };
    }
}
