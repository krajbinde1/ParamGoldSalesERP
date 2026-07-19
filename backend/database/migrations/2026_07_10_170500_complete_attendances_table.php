<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('attendance_date');
            $table->time('punch_in_time')->nullable();
            $table->time('punch_out_time')->nullable();
            $table->enum('attendance_status', ['Present', 'Absent', 'Half Day', 'Leave', 'Weekly Off'])->default('Present');
            $table->string('working_hours', 10)->nullable();
            $table->string('punch_in_location')->nullable();
            $table->string('punch_out_location')->nullable();
            $table->decimal('punch_in_latitude', 10, 7)->nullable();
            $table->decimal('punch_in_longitude', 10, 7)->nullable();
            $table->decimal('punch_out_latitude', 10, 7)->nullable();
            $table->decimal('punch_out_longitude', 10, 7)->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->enum('approval_status', ['Pending', 'Approved', 'Rejected'])->default('Pending');
            $table->softDeletes();
            $table->unique(['employee_id', 'attendance_date']);
            $table->index(['attendance_status', 'approval_status']);
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropUnique(['employee_id', 'attendance_date']);
            $table->dropIndex(['attendance_status', 'approval_status']);
            $table->dropForeign(['employee_id', 'approved_by']);
            $table->dropColumn(['employee_id', 'attendance_date', 'punch_in_time', 'punch_out_time', 'attendance_status', 'working_hours', 'punch_in_location', 'punch_out_location', 'punch_in_latitude', 'punch_in_longitude', 'punch_out_latitude', 'punch_out_longitude', 'remarks', 'approved_by', 'approval_status']);
        });
    }
};
