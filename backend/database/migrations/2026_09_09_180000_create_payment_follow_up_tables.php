<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureCyclesTable();
        $this->ensureEntriesTable();
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_follow_up_entries');
        Schema::dropIfExists('payment_follow_up_cycles');
    }

    private function ensureCyclesTable(): void
    {
        if (! Schema::hasTable('payment_follow_up_cycles')) {
            Schema::create('payment_follow_up_cycles', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('dealer_id');
                $table->unsignedBigInteger('employee_id');
                $table->unsignedInteger('cycle_number');
                $table->decimal('opening_outstanding', 15, 2);
                $table->timestamp('started_at');
                $table->string('status', 20)->default('open');
                $table->timestamp('closed_at')->nullable();
                $table->decimal('payment_received_amount', 15, 2)->nullable();
                $table->decimal('closing_outstanding', 15, 2)->nullable();
                $table->unsignedBigInteger('collection_id')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->foreign('dealer_id', 'pfu_cyc_dealer_fk')
                    ->references('id')->on('dealers')->restrictOnDelete();
                $table->foreign('employee_id', 'pfu_cyc_emp_fk')
                    ->references('id')->on('employees')->restrictOnDelete();
                $table->foreign('collection_id', 'pfu_cyc_collection_fk')
                    ->references('id')->on('collections')->nullOnDelete();
                $table->foreign('created_by', 'pfu_cyc_created_by_fk')
                    ->references('id')->on('users')->nullOnDelete();

                $table->unique(['dealer_id', 'cycle_number'], 'pfu_cyc_dealer_num_uq');
                $table->index(['dealer_id', 'status'], 'pfu_cyc_dealer_status_idx');
                $table->index(['employee_id', 'status'], 'pfu_cyc_emp_status_idx');
            });

            return;
        }

        $this->ensureIndex('payment_follow_up_cycles', ['dealer_id', 'cycle_number'], 'pfu_cyc_dealer_num_uq', unique: true);
        $this->ensureIndex('payment_follow_up_cycles', ['dealer_id', 'status'], 'pfu_cyc_dealer_status_idx');
        $this->ensureIndex('payment_follow_up_cycles', ['employee_id', 'status'], 'pfu_cyc_emp_status_idx');
    }

    private function ensureEntriesTable(): void
    {
        if (! Schema::hasTable('payment_follow_up_entries')) {
            Schema::create('payment_follow_up_entries', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('cycle_id');
                $table->unsignedBigInteger('dealer_id');
                $table->unsignedBigInteger('employee_id');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('entry_type', 30)->default('follow_up');
                $table->timestamp('followed_up_at');
                $table->text('remark');
                $table->decimal('outstanding_at_time', 15, 2);
                $table->decimal('expected_amount', 15, 2)->nullable();
                $table->date('next_follow_up_date')->nullable();
                $table->string('employee_notification_status', 20)->default('pending');
                $table->timestamp('employee_notification_sent_at')->nullable();
                $table->text('employee_notification_error')->nullable();
                $table->string('whatsapp_status', 20)->default('pending');
                $table->timestamp('whatsapp_sent_at')->nullable();
                $table->text('whatsapp_error')->nullable();
                $table->unsignedBigInteger('whatsapp_outbound_message_id')->nullable();
                $table->unsignedBigInteger('collection_id')->nullable();
                $table->timestamps();

                $table->foreign('cycle_id', 'pfu_ent_cycle_fk')
                    ->references('id')->on('payment_follow_up_cycles')->restrictOnDelete();
                $table->foreign('dealer_id', 'pfu_ent_dealer_fk')
                    ->references('id')->on('dealers')->restrictOnDelete();
                $table->foreign('employee_id', 'pfu_ent_emp_fk')
                    ->references('id')->on('employees')->restrictOnDelete();
                $table->foreign('created_by', 'pfu_ent_created_by_fk')
                    ->references('id')->on('users')->nullOnDelete();
                $table->foreign('collection_id', 'pfu_ent_collection_fk')
                    ->references('id')->on('collections')->nullOnDelete();

                $table->index(['cycle_id', 'id'], 'pfu_ent_cycle_id_idx');
                $table->index(['dealer_id', 'followed_up_at'], 'pfu_ent_dealer_fu_idx');
                $table->index(['next_follow_up_date', 'whatsapp_status'], 'pfu_next_date_wa_idx');
                $table->index(
                    ['next_follow_up_date', 'employee_notification_status'],
                    'pfu_next_date_notif_idx'
                );
                $table->index('entry_type', 'pfu_ent_type_idx');
            });

            return;
        }

        $this->ensureIndex('payment_follow_up_entries', ['cycle_id', 'id'], 'pfu_ent_cycle_id_idx');
        $this->ensureIndex('payment_follow_up_entries', ['dealer_id', 'followed_up_at'], 'pfu_ent_dealer_fu_idx');
        $this->ensureIndex('payment_follow_up_entries', ['next_follow_up_date', 'whatsapp_status'], 'pfu_next_date_wa_idx');
        $this->ensureIndex(
            'payment_follow_up_entries',
            ['next_follow_up_date', 'employee_notification_status'],
            'pfu_next_date_notif_idx'
        );
        $this->ensureIndex('payment_follow_up_entries', ['entry_type'], 'pfu_ent_type_idx');
    }

    /**
     * @param  list<string>  $columns
     */
    private function ensureIndex(string $table, array $columns, string $name, bool $unique = false): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? '') === $name) {
                return;
            }

            if (($index['columns'] ?? []) === $columns) {
                return;
            }
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name, $unique): void {
            if ($unique) {
                $blueprint->unique($columns, $name);

                return;
            }

            $blueprint->index($columns, $name);
        });
    }
};
