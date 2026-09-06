<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_transport_ledger_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('company_transport_ledger_entries', 'transport_charge_type')) {
                $table->string('transport_charge_type', 40)->nullable()->after('order_no');
                $table->index('transport_charge_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('company_transport_ledger_entries', function (Blueprint $table) {
            if (Schema::hasColumn('company_transport_ledger_entries', 'transport_charge_type')) {
                $table->dropIndex(['transport_charge_type']);
                $table->dropColumn('transport_charge_type');
            }
        });
    }
};
