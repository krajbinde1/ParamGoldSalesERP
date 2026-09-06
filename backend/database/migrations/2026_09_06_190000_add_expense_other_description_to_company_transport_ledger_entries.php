<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_transport_ledger_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('company_transport_ledger_entries', 'expense_other_description')) {
                $table->string('expense_other_description', 255)->nullable()->after('expense_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('company_transport_ledger_entries', function (Blueprint $table) {
            if (Schema::hasColumn('company_transport_ledger_entries', 'expense_other_description')) {
                $table->dropColumn('expense_other_description');
            }
        });
    }
};
