<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'job_role')) {
                $table->string('job_role', 100)->nullable()->after('role');
            }
        });

        foreach (['admin@paramgroup.in', 'admin@paramgold.in'] as $email) {
            DB::table('users')
                ->where('email', $email)
                ->update([
                    'name' => 'Admin',
                    'job_role' => 'Admin',
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'job_role')) {
                $table->dropColumn('job_role');
            }
        });
    }
};
