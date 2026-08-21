<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('held_by')->nullable()->after('last_edited_by_role')->constrained('users')->nullOnDelete();
            $table->timestamp('held_at')->nullable()->after('held_by');
            $table->text('hold_remark')->nullable()->after('held_at');
            $table->string('hold_return_status', 50)->nullable()->after('hold_remark');
            $table->foreignId('hold_released_by')->nullable()->after('hold_return_status')->constrained('users')->nullOnDelete();
            $table->timestamp('hold_released_at')->nullable()->after('hold_released_by');
            $table->foreignId('reverted_by')->nullable()->after('hold_released_at')->constrained('users')->nullOnDelete();
            $table->timestamp('reverted_at')->nullable()->after('reverted_by');
            $table->text('revert_remark')->nullable()->after('reverted_at');
            $table->foreignId('reapproved_by')->nullable()->after('revert_remark')->constrained('users')->nullOnDelete();
            $table->timestamp('reapproved_at')->nullable()->after('reapproved_by');
        });

        Schema::create('order_workflow_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('action', 40);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_role', 80)->nullable();
            $table->text('remark')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'created_at']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_workflow_events');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('held_by');
            $table->dropColumn(['held_at', 'hold_remark', 'hold_return_status']);
            $table->dropConstrainedForeignId('hold_released_by');
            $table->dropColumn('hold_released_at');
            $table->dropConstrainedForeignId('reverted_by');
            $table->dropColumn(['reverted_at', 'revert_remark']);
            $table->dropConstrainedForeignId('reapproved_by');
            $table->dropColumn('reapproved_at');
        });
    }
};
