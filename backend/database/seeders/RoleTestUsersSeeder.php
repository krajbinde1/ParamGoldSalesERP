<?php

namespace Database\Seeders;

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class RoleTestUsersSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            [
                'role' => UserRole::Manager,
                'full_name' => 'Test Manager',
                'mobile' => '9111111111',
                'email' => 'manager.test@paramgold.local',
            ],
            [
                'role' => UserRole::ProductionSupervisor,
                'full_name' => 'Test Production Supervisor',
                'mobile' => '9222222222',
                'email' => 'production.test@paramgold.local',
            ],
            [
                'role' => UserRole::Director,
                'full_name' => 'Test Director',
                'mobile' => '9333333333',
                'email' => 'director.test@paramgold.local',
            ],
        ];

        foreach ($roles as $profile) {
            if (User::query()->where('login_id', $profile['mobile'])->exists()) {
                continue;
            }

            app(CreateEmployeeWithUserAccount::class)->execute([
                'full_name' => $profile['full_name'],
                'mobile' => $profile['mobile'],
                'email' => $profile['email'],
                'department' => 'Sales',
                'designation' => $profile['role']->label(),
                'joining_date' => '2026-07-01',
                'salary' => 50000,
                'base_location' => 'Aurangabad',
                'daily_allowance' => 300,
                'travel_allowance_type' => 'actual_expense',
                'company_card_issued' => false,
                'monthly_travel_expense_limit' => 500,
                'aadhaar_number' => '23456789012'.substr($profile['mobile'], -1),
                'pan_number' => 'ABCDE123'.substr($profile['mobile'], -1).'F',
                'bank_name' => 'Test Bank',
                'account_number' => '12345678901'.substr($profile['mobile'], -1),
                'ifsc_code' => 'TEST0123456',
                'status' => true,
                'role' => $profile['role']->value,
            ]);
        }

        User::query()
            ->whereNotIn('role', UserRole::mobileValues())
            ->update(['role' => UserRole::Employee->value]);
    }
}
