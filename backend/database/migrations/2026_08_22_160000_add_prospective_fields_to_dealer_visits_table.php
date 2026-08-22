<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dealer_visits', function (Blueprint $table): void {
            $table->boolean('is_prospective')->default(false)->after('dealer_id');
            $table->string('prospective_firm_name')->nullable()->after('is_prospective');
            $table->string('prospective_owner_name')->nullable()->after('prospective_firm_name');
            $table->string('prospective_mobile', 15)->nullable()->after('prospective_owner_name');
            $table->string('prospective_village')->nullable()->after('prospective_mobile');
            $table->string('prospective_taluka')->nullable()->after('prospective_village');
            $table->string('prospective_district')->nullable()->after('prospective_taluka');
            $table->text('remarks')->nullable()->after('status');
        });

        $this->makeDealerIdNullable();
    }

    public function down(): void
    {
        Schema::table('dealer_visits', function (Blueprint $table): void {
            $table->dropColumn([
                'is_prospective',
                'prospective_firm_name',
                'prospective_owner_name',
                'prospective_mobile',
                'prospective_village',
                'prospective_taluka',
                'prospective_district',
                'remarks',
            ]);
        });
    }

    private function makeDealerIdNullable(): void
    {
        try {
            Schema::table('dealer_visits', function (Blueprint $table): void {
                $table->dropForeign(['dealer_id']);
            });
        } catch (Throwable) {
        }

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `dealer_visits` MODIFY `dealer_id` BIGINT UNSIGNED NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE dealer_visits ALTER COLUMN dealer_id DROP NOT NULL');
        } else {
            Schema::table('dealer_visits', function (Blueprint $table): void {
                $table->unsignedBigInteger('dealer_id')->nullable()->change();
            });
        }

        try {
            Schema::table('dealer_visits', function (Blueprint $table): void {
                $table->foreign('dealer_id')->references('id')->on('dealers')->nullOnDelete();
            });
        } catch (Throwable) {
        }
    }
};
