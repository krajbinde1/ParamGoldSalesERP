<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('company_transport_ledger_entry_orders')) {
            Schema::create('company_transport_ledger_entry_orders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('entry_id')->constrained('company_transport_ledger_entries')->cascadeOnDelete();
                $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
                $table->unique(['entry_id', 'order_id']);
                $table->index('order_id');
            });
        }

        if (
            ! Schema::hasTable('company_transport_ledger_entries')
            || ! Schema::hasColumn('company_transport_ledger_entries', 'order_id')
        ) {
            return;
        }

        $rows = DB::table('company_transport_ledger_entries')
            ->whereNotNull('order_id')
            ->where('entry_kind', 'debit')
            ->get(['id', 'order_id']);

        foreach ($rows as $row) {
            $exists = DB::table('company_transport_ledger_entry_orders')
                ->where('entry_id', $row->id)
                ->where('order_id', $row->order_id)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('company_transport_ledger_entry_orders')->insert([
                'entry_id' => $row->id,
                'order_id' => $row->order_id,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_transport_ledger_entry_orders');
    }
};
