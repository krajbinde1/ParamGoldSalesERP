<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attendances')) {
            return;
        }

        Schema::table('attendances', function (Blueprint $table): void {
            if (! Schema::hasColumn('attendances', 'punch_out_at')) {
                $table->timestamp('punch_out_at')->nullable()->after('punch_out_time');
            }
            if (! Schema::hasColumn('attendances', 'is_late_punch_out')) {
                $table->boolean('is_late_punch_out')->default(false)->after('punch_out_at');
            }
            if (! Schema::hasColumn('attendances', 'late_punch_out_reason')) {
                $table->string('late_punch_out_reason', 40)->nullable()->after('is_late_punch_out');
            }
            if (! Schema::hasColumn('attendances', 'late_punch_out_reason_note')) {
                $table->string('late_punch_out_reason_note', 500)->nullable()->after('late_punch_out_reason');
            }
            if (! Schema::hasColumn('attendances', 'punch_out_correction_status')) {
                $table->string('punch_out_correction_status', 20)->nullable()->after('late_punch_out_reason_note');
            }
        });

        if (! Schema::hasTable('attendance_punch_out_corrections')) {
            Schema::create('attendance_punch_out_corrections', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('attendance_id')->constrained('attendances')->cascadeOnDelete();
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('requested_punch_out_at');
                $table->string('reason', 40);
                $table->string('reason_note', 500)->nullable();
                $table->string('status', 20)->default('pending');
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->string('review_remark', 500)->nullable();
                $table->timestamps();

                $table->index(['attendance_id', 'status']);
                $table->index('status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_punch_out_corrections');

        if (! Schema::hasTable('attendances')) {
            return;
        }

        Schema::table('attendances', function (Blueprint $table): void {
            foreach ([
                'punch_out_correction_status',
                'late_punch_out_reason_note',
                'late_punch_out_reason',
                'is_late_punch_out',
                'punch_out_at',
            ] as $column) {
                if (Schema::hasColumn('attendances', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
