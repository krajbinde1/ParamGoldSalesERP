<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_outbound_messages', function (Blueprint $table): void {
            foreach (Schema::getIndexes('whatsapp_outbound_messages') as $index) {
                $columns = $index['columns'] ?? [];
                if (($index['unique'] ?? false) && $columns === ['source_type', 'source_id']) {
                    $table->dropUnique($index['name']);
                }
            }

            if (! Schema::hasIndex('whatsapp_outbound_messages', 'wa_outbound_source_idx')) {
                $table->index(['source_type', 'source_id'], 'wa_outbound_source_idx');
            }

            if (! Schema::hasColumn('whatsapp_outbound_messages', 'send_kind')) {
                $table->string('send_kind', 20)->default('auto')->after('source_id');
            }

            if (! Schema::hasColumn('whatsapp_outbound_messages', 'delivered_at')) {
                $table->timestamp('delivered_at')->nullable()->after('sent_at');
            }

            if (! Schema::hasIndex('whatsapp_outbound_messages', 'wa_outbound_meta_message_id_idx')) {
                $table->index('meta_message_id', 'wa_outbound_meta_message_id_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_outbound_messages', function (Blueprint $table): void {
            if (Schema::hasColumn('whatsapp_outbound_messages', 'delivered_at')) {
                $table->dropColumn('delivered_at');
            }
            if (Schema::hasColumn('whatsapp_outbound_messages', 'send_kind')) {
                $table->dropColumn('send_kind');
            }
            if (Schema::hasIndex('whatsapp_outbound_messages', 'wa_outbound_source_idx')) {
                $table->dropIndex('wa_outbound_source_idx');
            }
            if (Schema::hasIndex('whatsapp_outbound_messages', 'wa_outbound_meta_message_id_idx')) {
                $table->dropIndex('wa_outbound_meta_message_id_idx');
            }
            $table->unique(['source_type', 'source_id']);
        });
    }
};
