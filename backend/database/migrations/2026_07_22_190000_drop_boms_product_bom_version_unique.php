<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BOM version is no longer used for uniqueness or required on save.
 * Only one Active BOM per product is enforced in BOMCalculationService::ensureSingleActiveBom
 * (application-level; no DB partial unique required for SQLite test compatibility).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('boms')) {
            return;
        }

        Schema::table('boms', function (Blueprint $table) {
            try {
                if (method_exists(Schema::getConnection()->getSchemaBuilder(), 'hasIndex')
                    && Schema::hasIndex('boms', 'boms_product_id_bom_version_unique')) {
                    $table->dropUnique('boms_product_id_bom_version_unique');
                } else {
                    $table->dropUnique('boms_product_id_bom_version_unique');
                }
            } catch (\Throwable) {
                try {
                    $table->dropUnique(['product_id', 'bom_version']);
                } catch (\Throwable) {
                }
            }
        });

        if (Schema::hasColumn('boms', 'bom_version')) {
            Schema::table('boms', function (Blueprint $table) {
                $table->string('bom_version', 30)->nullable()->change();
            });
        }

        if (Schema::hasTable('production_batches') && Schema::hasColumn('production_batches', 'bom_version')) {
            Schema::table('production_batches', function (Blueprint $table) {
                $table->string('bom_version', 30)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('boms')) {
            return;
        }

        if (Schema::hasColumn('boms', 'bom_version')) {
            Schema::table('boms', function (Blueprint $table) {
                $table->string('bom_version', 30)->nullable(false)->change();
            });
        }

        Schema::table('boms', function (Blueprint $table) {
            try {
                $table->unique(['product_id', 'bom_version'], 'boms_product_id_bom_version_unique');
            } catch (\Throwable) {
            }
        });

        if (Schema::hasTable('production_batches') && Schema::hasColumn('production_batches', 'bom_version')) {
            Schema::table('production_batches', function (Blueprint $table) {
                $table->string('bom_version', 30)->nullable(false)->change();
            });
        }
    }
};
