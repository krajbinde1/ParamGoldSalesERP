<?php

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
            if (! Schema::hasColumn('dealer_tally_entries', 'removed_at')) {
                $table->timestamp('removed_at')->nullable()->after('source_row');
            }
            if (! Schema::hasColumn('dealer_tally_entries', 'removed_by')) {
                $table->foreignId('removed_by')->nullable()->after('removed_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('dealer_tally_entries', 'removal_reason')) {
                $table->text('removal_reason')->nullable()->after('removed_by');
            }
            if (! Schema::hasColumn('dealer_tally_entries', 'original_snapshot')) {
                $table->json('original_snapshot')->nullable()->after('removal_reason');
            }
        });

        if (Schema::hasColumn('dealer_tally_entries', 'removed_at')) {
            Schema::table('dealer_tally_entries', function (Blueprint $table): void {
                $table->index(['dealer_id', 'removed_at']);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('dealer_tally_entries')) {
            return;
        }

        Schema::table('dealer_tally_entries', function (Blueprint $table): void {
            if (Schema::hasColumn('dealer_tally_entries', 'removed_by')) {
                $table->dropConstrainedForeignId('removed_by');
            }
        });

        $columns = array_values(array_filter(
            ['removed_at', 'removal_reason', 'original_snapshot'],
            fn (string $column): bool => Schema::hasColumn('dealer_tally_entries', $column),
        ));

        if ($columns !== []) {
            Schema::table('dealer_tally_entries', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
