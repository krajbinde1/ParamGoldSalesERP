<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('remarks');
            $table->text('admin_remark')->nullable()->after('photo_path');
            $table->string('receipt_no')->nullable()->change();
        });

        DB::table('collections')->where('status', 'Pending')->update(['status' => 'pending']);
        DB::table('collections')->where('status', 'Verified')->update(['status' => 'received']);
        DB::table('collections')->where('status', 'Cancelled')->update(['status' => 'not_received']);

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE collections MODIFY status VARCHAR(20) NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE collections MODIFY status ENUM('Pending', 'Verified', 'Cancelled') NOT NULL DEFAULT 'Pending'");
        }

        Schema::table('collections', function (Blueprint $table) {
            $table->dropColumn(['photo_path', 'admin_remark']);
            $table->string('receipt_no')->nullable(false)->change();
        });
    }
};
