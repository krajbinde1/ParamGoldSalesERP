<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_INDEX = 'orders_source_credit_note_id_unique';

    private const SUPPORTING_INDEX = 'orders_source_credit_note_id_index';

    private const LINK_UNIQUE_INDEX = 'orders_credit_note_link_unique';

    public function up(): void
    {
        // MySQL uses orders_source_credit_note_id_unique as the index for
        // orders_source_credit_note_id_foreign. Add another index on the same
        // column before dropping the unique one, or MySQL error 1553 is raised.
        $this->ensureNonUniqueIndex('orders', 'source_credit_note_id', self::SUPPORTING_INDEX);

        if (Schema::hasIndex('orders', self::UNIQUE_INDEX)) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropUnique(self::UNIQUE_INDEX);
            });
        }

        if (! Schema::hasColumn('orders', 'credit_note_link_role')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->string('credit_note_link_role', 32)->nullable()->after('source_credit_note_id');
            });
        }

        if (! Schema::hasColumn('orders', 'paired_order_id')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->foreignId('paired_order_id')
                    ->nullable()
                    ->after('credit_note_link_role')
                    ->constrained('orders')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasColumn('orders', 'credit_note_link_role')) {
            DB::table('orders')
                ->whereNotNull('source_credit_note_id')
                ->whereNull('credit_note_link_role')
                ->update(['credit_note_link_role' => 'destination']);
        }

        if (! Schema::hasIndex('orders', self::LINK_UNIQUE_INDEX)) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->unique(
                    ['source_credit_note_id', 'credit_note_link_role'],
                    self::LINK_UNIQUE_INDEX,
                );
            });
        }

        if (! Schema::hasColumn('credit_notes', 'linked_source_order_id')) {
            Schema::table('credit_notes', function (Blueprint $table): void {
                $table->foreignId('linked_source_order_id')
                    ->nullable()
                    ->after('linked_order_id')
                    ->constrained('orders')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! $this->sourceCreditNoteReferencesAreUnique()) {
            throw new \RuntimeException(
                'Cannot restore the unique index on orders.source_credit_note_id while more than one order references the same credit note. No orders or credit notes were deleted.'
            );
        }

        if (Schema::hasColumn('credit_notes', 'linked_source_order_id')) {
            Schema::table('credit_notes', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('linked_source_order_id');
            });
        }

        if (! Schema::hasIndex('orders', self::UNIQUE_INDEX)) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->unique('source_credit_note_id', self::UNIQUE_INDEX);
            });
        }

        if (Schema::hasIndex('orders', self::LINK_UNIQUE_INDEX)) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropUnique(self::LINK_UNIQUE_INDEX);
            });
        }

        if (Schema::hasIndex('orders', self::SUPPORTING_INDEX)) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropIndex(self::SUPPORTING_INDEX);
            });
        }

        if (Schema::hasColumn('orders', 'paired_order_id')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('paired_order_id');
            });
        }

        if (Schema::hasColumn('orders', 'credit_note_link_role')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropColumn('credit_note_link_role');
            });
        }
    }

    private function ensureNonUniqueIndex(string $table, string $column, string $indexName): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            $columns = array_values($index['columns'] ?? []);
            $isNonUnique = ($index['unique'] ?? false) !== true && ($index['primary'] ?? false) !== true;

            if ($isNonUnique && ($columns[0] ?? null) === $column) {
                return;
            }
        }

        if (! Schema::hasIndex($table, $indexName)) {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $indexName): void {
                $blueprint->index($column, $indexName);
            });
        }
    }

    private function sourceCreditNoteReferencesAreUnique(): bool
    {
        $duplicate = DB::table('orders')
            ->select('source_credit_note_id')
            ->whereNotNull('source_credit_note_id')
            ->groupBy('source_credit_note_id')
            ->havingRaw('COUNT(*) > 1')
            ->limit(1)
            ->get();

        return $duplicate->isEmpty();
    }
};
