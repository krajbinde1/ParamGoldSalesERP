<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasEmployeeId = Schema::hasColumn('users', 'employee_id');
        $hasLoginId = Schema::hasColumn('users', 'login_id');
        $hasMustChangePassword = Schema::hasColumn('users', 'must_change_password');

        Schema::table('users', function (Blueprint $table) use ($hasEmployeeId, $hasLoginId, $hasMustChangePassword): void {
            if (! $hasEmployeeId) {
                $table->foreignId('employee_id')
                    ->nullable()
                    ->unique()
                    ->after('id')
                    ->constrained('employees')
                    ->nullOnDelete();
            }

            if (! $hasLoginId) {
                $table->string('login_id')->nullable()->unique()->after('email');
            }

            if (! $hasMustChangePassword) {
                $table->boolean('must_change_password')->default(false)->after('password');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'employee_id')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('employee_id');
            });
        }

        Schema::table('users', function (Blueprint $table): void {
            $columns = collect(['login_id', 'must_change_password'])
                ->filter(fn (string $column): bool => Schema::hasColumn('users', $column))
                ->all();

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
