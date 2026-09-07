<?php

namespace App\Console\Commands;

use App\Actions\Employees\RepairTestManagerEmployee;
use Illuminate\Console\Command;

class RepairTestManagerEmployeeCommand extends Command
{
    protected $signature = 'employees:repair-test-manager';

    protected $description = 'Create/link the Test Manager employee and assign Test Sales Employee without changing other reporting managers';

    public function handle(RepairTestManagerEmployee $repair): int
    {
        $result = $repair->execute();
        $manager = $result['manager_employee'];
        $user = $result['manager_user'];
        $sales = $result['sales_employee'];

        $this->info('Test Manager employee id='.$manager->id.' code='.$manager->employee_code);
        $this->info('Test Manager user id='.$user->id.' login='.$user->login_id.' role='.$user->role.' employee_id='.$user->employee_id);
        $this->info('Test Sales Employee id='.$sales->id.' reporting_manager_id='.$sales->reporting_manager_id);
        $this->info('manager_created='.($result['manager_created'] ? 'yes' : 'no').' manager_linked='.($result['manager_linked'] ? 'yes' : 'no').' sales_created='.($result['sales_created'] ? 'yes' : 'no'));

        return self::SUCCESS;
    }
}
