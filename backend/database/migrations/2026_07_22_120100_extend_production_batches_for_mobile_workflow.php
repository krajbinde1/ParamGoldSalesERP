<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_batches', function (Blueprint $table) {
            if (! Schema::hasColumn('production_batches', 'has_material_deviation')) {
                $table->boolean('has_material_deviation')->default(false)->after('notes');
            }
            if (! Schema::hasColumn('production_batches', 'requires_approval')) {
                $table->boolean('requires_approval')->default(false)->after('has_material_deviation');
            }
            if (! Schema::hasColumn('production_batches', 'has_quantity_variance')) {
                $table->boolean('has_quantity_variance')->default(false)->after('requires_approval');
            }
            if (! Schema::hasColumn('production_batches', 'submitted_for_approval_at')) {
                $table->timestamp('submitted_for_approval_at')->nullable()->after('has_quantity_variance');
            }
            if (! Schema::hasColumn('production_batches', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('submitted_for_approval_at');
                $table->foreign('approved_by', 'pb_approved_by_fk')->references('id')->on('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('production_batches', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }
            if (! Schema::hasColumn('production_batches', 'rejected_by')) {
                $table->unsignedBigInteger('rejected_by')->nullable()->after('approved_at');
                $table->foreign('rejected_by', 'pb_rejected_by_fk')->references('id')->on('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('production_batches', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            }
            if (! Schema::hasColumn('production_batches', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('rejected_at');
            }
            if (! Schema::hasColumn('production_batches', 'approval_notes')) {
                $table->text('approval_notes')->nullable()->after('rejection_reason');
            }
            if (! Schema::hasColumn('production_batches', 'started_at')) {
                $table->timestamp('started_at')->nullable()->after('approval_notes');
            }
        });

        Schema::table('production_batch_consumptions', function (Blueprint $table) {
            if (! Schema::hasColumn('production_batch_consumptions', 'bom_item_id')) {
                $table->unsignedBigInteger('bom_item_id')->nullable()->after('production_batch_id');
                $table->foreign('bom_item_id', 'pbc_bom_item_fk')->references('id')->on('bom_items')->nullOnDelete();
            }
            if (! Schema::hasColumn('production_batch_consumptions', 'original_raw_material_id')) {
                $table->unsignedBigInteger('original_raw_material_id')->nullable()->after('packaging_material_id');
                $table->foreign('original_raw_material_id', 'pbc_orig_raw_fk')->references('id')->on('raw_materials')->nullOnDelete();
            }
            if (! Schema::hasColumn('production_batch_consumptions', 'original_packaging_material_id')) {
                $table->unsignedBigInteger('original_packaging_material_id')->nullable()->after('original_raw_material_id');
                $table->foreign('original_packaging_material_id', 'pbc_orig_pack_fk')->references('id')->on('packaging_materials')->nullOnDelete();
            }
            if (! Schema::hasColumn('production_batch_consumptions', 'original_material_name')) {
                $table->string('original_material_name')->nullable()->after('material_name');
            }
            if (! Schema::hasColumn('production_batch_consumptions', 'is_substituted')) {
                $table->boolean('is_substituted')->default(false)->after('is_optional');
            }
            if (! Schema::hasColumn('production_batch_consumptions', 'substitution_reason')) {
                $table->string('substitution_reason')->nullable()->after('is_substituted');
            }
            if (! Schema::hasColumn('production_batch_consumptions', 'substitution_remarks')) {
                $table->text('substitution_remarks')->nullable()->after('substitution_reason');
            }
            if (! Schema::hasColumn('production_batch_consumptions', 'standard_quantity')) {
                $table->decimal('standard_quantity', 14, 4)->default(0)->after('required_quantity');
            }
            if (! Schema::hasColumn('production_batch_consumptions', 'variance_quantity')) {
                $table->decimal('variance_quantity', 14, 4)->default(0)->after('consumed_quantity');
            }
            if (! Schema::hasColumn('production_batch_consumptions', 'variance_percentage')) {
                $table->decimal('variance_percentage', 8, 3)->default(0)->after('variance_quantity');
            }
            if (! Schema::hasColumn('production_batch_consumptions', 'conversion_ratio')) {
                $table->decimal('conversion_ratio', 12, 6)->default(1)->after('variance_percentage');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'can_view_production_costs')) {
                $table->boolean('can_view_production_costs')->default(false)->after('job_role');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'can_view_production_costs')) {
                $table->dropColumn('can_view_production_costs');
            }
        });

        Schema::table('production_batch_consumptions', function (Blueprint $table) {
            foreach (['pbc_bom_item_fk', 'pbc_orig_raw_fk', 'pbc_orig_pack_fk'] as $fk) {
                try {
                    $table->dropForeign($fk);
                } catch (\Throwable) {
                    // ignore
                }
            }

            $cols = [
                'bom_item_id',
                'original_raw_material_id',
                'original_packaging_material_id',
                'original_material_name',
                'is_substituted',
                'substitution_reason',
                'substitution_remarks',
                'standard_quantity',
                'variance_quantity',
                'variance_percentage',
                'conversion_ratio',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('production_batch_consumptions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('production_batches', function (Blueprint $table) {
            foreach (['pb_approved_by_fk', 'pb_rejected_by_fk'] as $fk) {
                try {
                    $table->dropForeign($fk);
                } catch (\Throwable) {
                    // ignore
                }
            }

            $cols = [
                'has_material_deviation',
                'requires_approval',
                'has_quantity_variance',
                'submitted_for_approval_at',
                'approved_by',
                'approved_at',
                'rejected_by',
                'rejected_at',
                'rejection_reason',
                'approval_notes',
                'started_at',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('production_batches', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
