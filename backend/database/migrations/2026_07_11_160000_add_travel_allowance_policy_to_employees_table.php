<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'travel_allowance_type',
            'rate_per_km',
            'daily_km_limit',
            'monthly_km_limit',
            'company_card_issued',
            'monthly_travel_expense_limit',
            'company_card_last_four',
        ];

        Schema::table('employees', function (Blueprint $table) use ($columns): void {
            if (! Schema::hasColumn('employees', $columns[0])) {
                $table->string('travel_allowance_type')->nullable()->after('travel_allowance');
            }
            if (! Schema::hasColumn('employees', $columns[1])) {
                $table->decimal('rate_per_km', 10, 2)->nullable()->after('travel_allowance_type');
            }
            if (! Schema::hasColumn('employees', $columns[2])) {
                $table->decimal('daily_km_limit', 10, 2)->nullable()->after('rate_per_km');
            }
            if (! Schema::hasColumn('employees', $columns[3])) {
                $table->decimal('monthly_km_limit', 10, 2)->nullable()->after('daily_km_limit');
            }
            if (! Schema::hasColumn('employees', $columns[4])) {
                $table->boolean('company_card_issued')->default(false)->after('monthly_km_limit');
            }
            if (! Schema::hasColumn('employees', $columns[5])) {
                $table->decimal('monthly_travel_expense_limit', 12, 2)->nullable()->after('company_card_issued');
            }
            if (! Schema::hasColumn('employees', $columns[6])) {
                $table->string('company_card_last_four', 4)->nullable()->after('monthly_travel_expense_limit');
            }
        });

        // Preserve the legacy fixed allowance as the initial monthly expense
        // limit. The old column remains available for audit/manual review.
        DB::table('employees')
            ->whereNull('travel_allowance_type')
            ->update([
                'travel_allowance_type' => 'actual_expense',
                'monthly_travel_expense_limit' => DB::raw('travel_allowance'),
            ]);
    }

    public function down(): void
    {
        $columns = collect([
            'travel_allowance_type',
            'rate_per_km',
            'daily_km_limit',
            'monthly_km_limit',
            'company_card_issued',
            'monthly_travel_expense_limit',
            'company_card_last_four',
        ])->filter(fn (string $column): bool => Schema::hasColumn('employees', $column))->all();

        if ($columns !== []) {
            Schema::table('employees', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
