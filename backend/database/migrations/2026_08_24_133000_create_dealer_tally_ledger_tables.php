<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tally_dealer_mappings')) {
            Schema::create('tally_dealer_mappings', function (Blueprint $table): void {
                $table->id();
                $table->string('tally_ledger_name');
                $table->string('tally_ledger_name_normalized')->unique();
                $table->foreignId('dealer_id')->constrained('dealers')->restrictOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index('dealer_id');
            });
        }

        if (! Schema::hasTable('dealer_tally_imports')) {
            Schema::create('dealer_tally_imports', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('dealer_id')->constrained('dealers')->cascadeOnDelete();
                $table->string('original_filename');
                $table->string('tally_ledger_name')->nullable();
                $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('imported_at');
                $table->decimal('opening_balance', 14, 2)->default(0);
                $table->string('opening_balance_type', 10)->default('debit');
                $table->unsignedInteger('transaction_count')->default(0);
                $table->unsignedInteger('imported_count')->default(0);
                $table->unsignedInteger('duplicate_count')->default(0);
                $table->unsignedInteger('failed_count')->default(0);
                $table->decimal('tally_closing_balance', 14, 2)->nullable();
                $table->string('tally_closing_balance_type', 10)->nullable();
                $table->decimal('erp_closing_balance', 14, 2)->default(0);
                $table->string('erp_closing_balance_type', 10)->default('debit');
                $table->boolean('balance_matched')->nullable();
                $table->decimal('difference', 14, 2)->nullable();
                $table->string('status', 20)->default('completed');
                $table->json('failed_rows')->nullable();
                $table->timestamps();

                $table->index(['dealer_id', 'imported_at']);
            });
        }

        if (! Schema::hasTable('dealer_tally_ledgers')) {
            Schema::create('dealer_tally_ledgers', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('dealer_id')->unique()->constrained('dealers')->cascadeOnDelete();
                $table->decimal('opening_balance', 14, 2)->default(0);
                $table->string('opening_balance_type', 10)->default('debit');
                $table->boolean('opening_balance_explicit')->default(false);
                $table->date('financial_start_date')->default('2026-04-01');
                $table->decimal('tally_closing_balance', 14, 2)->nullable();
                $table->string('tally_closing_balance_type', 10)->nullable();
                $table->timestamp('last_imported_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('dealer_tally_entries')) {
            Schema::create('dealer_tally_entries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('dealer_id')->constrained('dealers')->cascadeOnDelete();
                $table->foreignId('import_id')->nullable()->constrained('dealer_tally_imports')->nullOnDelete();
                $table->date('entry_date');
                $table->string('particulars');
                $table->string('voucher_type')->nullable();
                $table->string('voucher_no')->nullable();
                $table->decimal('debit', 14, 2)->default(0);
                $table->decimal('credit', 14, 2)->default(0);
                $table->string('source', 30)->default('tally_import');
                $table->string('fingerprint', 64);
                $table->unsignedInteger('source_row')->nullable();
                $table->timestamps();

                $table->unique('fingerprint');
                $table->index(['dealer_id', 'entry_date']);
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: do not drop existing Tally tables or data.
    }
};
