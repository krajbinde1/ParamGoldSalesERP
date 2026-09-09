<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_follow_up_entries')) {
            return;
        }

        Schema::table('payment_follow_up_entries', function (Blueprint $table): void {
            if (! Schema::hasColumn('payment_follow_up_entries', 'commitment_whatsapp_status')) {
                $table->string('commitment_whatsapp_status', 20)
                    ->default('pending')
                    ->after('whatsapp_outbound_message_id');
            }
            if (! Schema::hasColumn('payment_follow_up_entries', 'commitment_whatsapp_sent_at')) {
                $table->timestamp('commitment_whatsapp_sent_at')
                    ->nullable()
                    ->after('commitment_whatsapp_status');
            }
            if (! Schema::hasColumn('payment_follow_up_entries', 'commitment_whatsapp_error')) {
                $table->text('commitment_whatsapp_error')
                    ->nullable()
                    ->after('commitment_whatsapp_sent_at');
            }
            if (! Schema::hasColumn('payment_follow_up_entries', 'commitment_whatsapp_outbound_message_id')) {
                $table->unsignedBigInteger('commitment_whatsapp_outbound_message_id')
                    ->nullable()
                    ->after('commitment_whatsapp_error');
            }
        });

        $this->ensureIndex(
            'payment_follow_up_entries',
            ['commitment_whatsapp_status'],
            'pfu_ent_cmt_wa_idx',
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('payment_follow_up_entries')) {
            return;
        }

        Schema::table('payment_follow_up_entries', function (Blueprint $table): void {
            foreach (Schema::getIndexes('payment_follow_up_entries') as $index) {
                if (($index['name'] ?? '') === 'pfu_ent_cmt_wa_idx') {
                    $table->dropIndex('pfu_ent_cmt_wa_idx');
                    break;
                }
            }

            $columns = array_values(array_filter([
                Schema::hasColumn('payment_follow_up_entries', 'commitment_whatsapp_outbound_message_id')
                    ? 'commitment_whatsapp_outbound_message_id'
                    : null,
                Schema::hasColumn('payment_follow_up_entries', 'commitment_whatsapp_error')
                    ? 'commitment_whatsapp_error'
                    : null,
                Schema::hasColumn('payment_follow_up_entries', 'commitment_whatsapp_sent_at')
                    ? 'commitment_whatsapp_sent_at'
                    : null,
                Schema::hasColumn('payment_follow_up_entries', 'commitment_whatsapp_status')
                    ? 'commitment_whatsapp_status'
                    : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function ensureIndex(string $table, array $columns, string $name): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? '') === $name) {
                return;
            }

            if (($index['columns'] ?? []) === $columns) {
                return;
            }
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name): void {
            $blueprint->index($columns, $name);
        });
    }
};
