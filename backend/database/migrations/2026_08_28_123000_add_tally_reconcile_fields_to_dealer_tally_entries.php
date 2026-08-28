<?php

use App\Services\Dealers\DealerSalesLedgerReconciler;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dealer_tally_entries')) {
            return;
        }

        Schema::table('dealer_tally_entries', function (Blueprint $table): void {
            if (! Schema::hasColumn('dealer_tally_entries', 'tally_voucher_type')) {
                $table->string('tally_voucher_type')->nullable()->after('voucher_no');
            }
            if (! Schema::hasColumn('dealer_tally_entries', 'tally_voucher_no')) {
                $table->string('tally_voucher_no')->nullable()->after('tally_voucher_type');
            }
            if (! Schema::hasColumn('dealer_tally_entries', 'tally_entry_date')) {
                $table->date('tally_entry_date')->nullable()->after('tally_voucher_no');
            }
            if (! Schema::hasColumn('dealer_tally_entries', 'tally_reconciled_at')) {
                $table->timestamp('tally_reconciled_at')->nullable()->after('tally_entry_date');
            }
        });

        app(DealerSalesLedgerReconciler::class)->reconcileExistingDuplicates();
    }

    public function down(): void
    {
        if (! Schema::hasTable('dealer_tally_entries')) {
            return;
        }

        Schema::table('dealer_tally_entries', function (Blueprint $table): void {
            foreach (['tally_reconciled_at', 'tally_entry_date', 'tally_voucher_no', 'tally_voucher_type'] as $column) {
                if (Schema::hasColumn('dealer_tally_entries', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
