<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Collapse legacy approval statuses into draft (still editable / postable).
        DB::table('raw_material_inwards')
            ->whereIn('status', ['pending_approval', 'approved'])
            ->update([
                'status' => 'draft',
                'approved_by' => null,
                'approved_at' => null,
            ]);

        DB::table('packaging_material_inwards')
            ->whereIn('status', ['pending_approval', 'approved'])
            ->update([
                'status' => 'draft',
                'approved_by' => null,
                'approved_at' => null,
            ]);

        Schema::table('raw_material_inward_items', function (Blueprint $table) {
            if (! Schema::hasColumn('raw_material_inward_items', 'stock_before')) {
                $table->decimal('stock_before', 14, 3)->nullable()->after('unit');
            }
            if (! Schema::hasColumn('raw_material_inward_items', 'stock_after')) {
                $table->decimal('stock_after', 14, 3)->nullable()->after('stock_before');
            }
        });

        Schema::table('packaging_material_inward_items', function (Blueprint $table) {
            if (! Schema::hasColumn('packaging_material_inward_items', 'stock_before')) {
                $table->decimal('stock_before', 14, 3)->nullable()->after('unit');
            }
            if (! Schema::hasColumn('packaging_material_inward_items', 'stock_after')) {
                $table->decimal('stock_after', 14, 3)->nullable()->after('stock_before');
            }
        });

        Schema::table('stock_ledgers', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_ledgers', 'old_average_rate')) {
                $table->decimal('old_average_rate', 14, 4)->nullable()->after('rate');
            }
            if (! Schema::hasColumn('stock_ledgers', 'new_average_rate')) {
                $table->decimal('new_average_rate', 14, 4)->nullable()->after('old_average_rate');
            }
            if (! Schema::hasColumn('stock_ledgers', 'supplier_invoice_number')) {
                $table->string('supplier_invoice_number', 100)->nullable()->after('reference_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stock_ledgers', function (Blueprint $table) {
            if (Schema::hasColumn('stock_ledgers', 'supplier_invoice_number')) {
                $table->dropColumn('supplier_invoice_number');
            }
            if (Schema::hasColumn('stock_ledgers', 'new_average_rate')) {
                $table->dropColumn('new_average_rate');
            }
            if (Schema::hasColumn('stock_ledgers', 'old_average_rate')) {
                $table->dropColumn('old_average_rate');
            }
        });

        Schema::table('raw_material_inward_items', function (Blueprint $table) {
            $cols = array_values(array_filter([
                Schema::hasColumn('raw_material_inward_items', 'stock_before') ? 'stock_before' : null,
                Schema::hasColumn('raw_material_inward_items', 'stock_after') ? 'stock_after' : null,
            ]));
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });

        Schema::table('packaging_material_inward_items', function (Blueprint $table) {
            $cols = array_values(array_filter([
                Schema::hasColumn('packaging_material_inward_items', 'stock_before') ? 'stock_before' : null,
                Schema::hasColumn('packaging_material_inward_items', 'stock_after') ? 'stock_after' : null,
            ]));
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
};
