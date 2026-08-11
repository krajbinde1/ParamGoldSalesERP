<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attendances')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE attendances MODIFY COLUMN attendance_status ENUM('Present', 'Absent', 'Half Day', 'Leave', 'Weekly Off', 'Punched In') NOT NULL DEFAULT 'Punched In'");
        }

        // Safe backfill: only recalculate punch-based rows (never Leave / Weekly Off).
        // Open punches → Punched In. Completed punches → Present / Half Day / Absent from duration.
        $rows = DB::table('attendances')
            ->whereNotIn('attendance_status', ['Leave', 'Weekly Off'])
            ->whereNotNull('punch_in_time')
            ->orderBy('id')
            ->get(['id', 'attendance_date', 'punch_in_time', 'punch_out_time', 'total_working_minutes', 'attendance_status']);

        foreach ($rows as $row) {
            $status = $this->resolveStatus($row);

            if ($status !== null && $status !== $row->attendance_status) {
                DB::table('attendances')
                    ->where('id', $row->id)
                    ->update(['attendance_status' => $status]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('attendances')) {
            return;
        }

        // Convert Punched In back to Present before shrinking enum (MySQL).
        DB::table('attendances')
            ->where('attendance_status', 'Punched In')
            ->update(['attendance_status' => 'Present']);

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE attendances MODIFY COLUMN attendance_status ENUM('Present', 'Absent', 'Half Day', 'Leave', 'Weekly Off') NOT NULL DEFAULT 'Present'");
        }
    }

    private function resolveStatus(object $row): ?string
    {
        if (blank($row->punch_out_time)) {
            return 'Punched In';
        }

        $minutes = $row->total_working_minutes;

        if ($minutes === null || (int) $minutes <= 0) {
            try {
                $date = (string) $row->attendance_date;
                $in = \Illuminate\Support\Carbon::parse($date.' '.$row->punch_in_time, 'Asia/Kolkata');
                $out = \Illuminate\Support\Carbon::parse($date.' '.$row->punch_out_time, 'Asia/Kolkata');
                if ($out->lessThan($in)) {
                    $out->addDay();
                }
                $minutes = $in->diffInMinutes($out);
            } catch (\Throwable) {
                return null;
            }
        }

        $minutes = (int) $minutes;

        if ($minutes >= 480) {
            return 'Present';
        }

        if ($minutes >= 240) {
            return 'Half Day';
        }

        return 'Absent';
    }
};
