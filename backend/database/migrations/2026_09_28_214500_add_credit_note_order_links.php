<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique(['source_credit_note_id']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->string('credit_note_link_role', 32)->nullable()->after('source_credit_note_id');
            $table->foreignId('paired_order_id')
                ->nullable()
                ->after('credit_note_link_role')
                ->constrained('orders')
                ->nullOnDelete();
        });

        DB::table('orders')
            ->whereNotNull('source_credit_note_id')
            ->whereNull('credit_note_link_role')
            ->update(['credit_note_link_role' => 'destination']);

        Schema::table('orders', function (Blueprint $table): void {
            $table->unique(
                ['source_credit_note_id', 'credit_note_link_role'],
                'orders_credit_note_link_unique',
            );
        });

        Schema::table('credit_notes', function (Blueprint $table): void {
            $table->foreignId('linked_source_order_id')
                ->nullable()
                ->after('linked_order_id')
                ->constrained('orders')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('credit_notes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('linked_source_order_id');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique('orders_credit_note_link_unique');
            $table->dropConstrainedForeignId('paired_order_id');
            $table->dropColumn('credit_note_link_role');
            $table->unique('source_credit_note_id');
        });
    }
};
