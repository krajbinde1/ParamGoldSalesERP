<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'received_copy_path')) {
                $table->string('received_copy_path')->nullable()->after('lr_document_path');
            }
            if (! Schema::hasColumn('orders', 'received_copy_uploaded_by')) {
                $table->foreignId('received_copy_uploaded_by')->nullable()->after('received_copy_path')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('orders', 'received_copy_uploaded_at')) {
                $table->timestamp('received_copy_uploaded_at')->nullable()->after('received_copy_uploaded_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'received_copy_uploaded_by')) {
                $table->dropConstrainedForeignId('received_copy_uploaded_by');
            }
            foreach (['received_copy_uploaded_at', 'received_copy_path'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
