<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\EmployeeRoutePoint;
use App\Services\Attendance\AttendanceStatusCalculator;
use App\Support\AttendanceCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    private function ok(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        $body = ['success' => $status < 400, 'message' => $message, 'data' => $data];
        Log::info('Attendance API response', ['status' => $status, 'body' => $body]);

        return response()->json($body, $status);
    }

    private function logRequest(Request $request): void
    {
        Log::info('Attendance API request', [
            'user_id' => $request->user()?->id,
            'employee_id' => $request->user()?->employee?->id,
            'method' => $request->method(),
            'path' => $request->path(),
            'body' => $request->except('photo'),
            'photo' => $request->file('photo')?->getClientOriginalName(),
        ]);
    }

    private function rules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'location_address' => ['required', 'string', 'max:255'],
            'photo' => ['required', 'image', 'max:5120'],
            'remarks' => ['nullable', 'string'],
        ];
    }

    private function employeeId(Request $request): int
    {
        $employee = $request->user()->employee;

        if ($employee === null) {
            throw ValidationException::withMessages([
                'employee' => 'Employee profile is not linked to this account.',
            ]);
        }

        return $employee->id;
    }

    public function punchIn(Request $request): JsonResponse
    {
        $this->logRequest($request);
        $validated = $request->validate($this->rules());
        $employeeId = $this->employeeId($request);
        $today = Attendance::businessToday()->toDateString();
        $now = Attendance::businessNow();

        if (Attendance::query()
            ->where('employee_id', $employeeId)
            ->whereDate('attendance_date', $today)
            ->exists()) {
            return $this->ok('Already punched in.', null, 422);
        }

        $attendance = Attendance::query()->create([
            'employee_id' => $employeeId,
            'attendance_date' => $today,
            'punch_in_time' => $now->format('H:i:s'),
            'punch_in_latitude' => $validated['latitude'],
            'punch_in_longitude' => $validated['longitude'],
            'punch_in_location' => $validated['location_address'],
            'punch_in_photo' => str_replace('\\', '/', $request->file('photo')->store('attendance', 'public')),
            'remarks' => $validated['remarks'] ?? null,
            'attendance_status' => AttendanceStatusCalculator::STATUS_PUNCHED_IN,
            'approval_status' => 'Pending',
        ]);

        return $this->ok('Punch in recorded.', $this->formatAttendance($attendance), 201);
    }

    public function punchOut(Request $request): JsonResponse
    {
        $this->logRequest($request);
        $validated = $request->validate($this->rules());
        $employeeId = $this->employeeId($request);
        $today = Attendance::businessToday()->toDateString();
        $now = Attendance::businessNow();

        $attendance = Attendance::query()
            ->where('employee_id', $employeeId)
            ->whereDate('attendance_date', $today)
            ->first();

        if ($attendance === null) {
            return $this->ok('Punch in is required first.', null, 422);
        }

        if ($attendance->punch_out_time) {
            return $this->ok('Already punched out.', null, 422);
        }

        $attendance->update([
            'punch_out_time' => $now->format('H:i:s'),
            'punch_out_latitude' => $validated['latitude'],
            'punch_out_longitude' => $validated['longitude'],
            'punch_out_location' => $validated['location_address'],
            'punch_out_photo' => str_replace('\\', '/', $request->file('photo')->store('attendance', 'public')),
            'total_working_minutes' => $attendance->punchInAt()?->diffInMinutes($now),
        ]);

        $fresh = $attendance->fresh();
        app(\App\Services\EmployeeRouteAnalysisService::class)
            ->recalculateAndPersistDistance($fresh);

        return $this->ok('Punch out recorded.', $this->formatAttendance($fresh->fresh()));
    }

    public function today(Request $request): JsonResponse
    {
        $this->logRequest($request);
        $employeeId = $this->employeeId($request);
        $today = Attendance::businessToday()->toDateString();

        $attendance = Attendance::query()
            ->where('employee_id', $employeeId)
            ->whereDate('attendance_date', $today)
            ->first();

        return $this->ok('Today attendance.', [
            'attendance' => $attendance ? $this->formatAttendance($attendance) : null,
            'punch_in_allowed' => $attendance === null,
            'punch_out_allowed' => $attendance !== null && blank($attendance->punch_out_time),
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $this->logRequest($request);
        $employeeId = $this->employeeId($request);

        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'attendance_status' => ['nullable', 'string'],
            'approval_status' => ['nullable', 'string'],
        ]);

        $query = Attendance::query()
            ->where('employee_id', $employeeId)
            ->latest('attendance_date')
            ->latest('id');

        foreach (['attendance_status', 'approval_status'] as $field) {
            if (! empty($validated[$field])) {
                $query->where($field, $validated[$field]);
            }
        }

        if (! empty($validated['date_from'])) {
            $query->whereDate('attendance_date', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('attendance_date', '<=', $validated['date_to']);
        }

        $records = $query->get()->map(fn (Attendance $attendance): array => $this->formatAttendance($attendance))->values();

        return $this->ok('Attendance history.', $records);
    }

    public function monthlySummary(Request $request): JsonResponse
    {
        $this->logRequest($request);
        $employeeId = $this->employeeId($request);

        $validated = $request->validate([
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);

        $month = (int) ($validated['month'] ?? Attendance::businessNow()->month);
        $year = (int) ($validated['year'] ?? Attendance::businessNow()->year);

        return $this->ok(
            'Monthly attendance summary.',
            Attendance::monthlySummary($employeeId, $month, $year),
        );
    }

    // TEST ONLY - REMOVE BEFORE PRODUCTION
    public function resetToday(Request $request): JsonResponse
    {
        $employeeId = $this->employeeId($request);
        $today = Attendance::businessToday()->toDateString();

        $attendance = Attendance::query()
            ->where('employee_id', $employeeId)
            ->whereDate('attendance_date', $today)
            ->first();

        if ($attendance === null) {
            return response()->json([
                'success' => true,
                'message' => 'No attendance found for today.',
            ]);
        }

        DB::transaction(function () use ($attendance): void {
            EmployeeRoutePoint::query()
                ->where('attendance_id', $attendance->id)
                ->delete();

            $attendance->forceDelete();
        });

        return response()->json([
            'success' => true,
            'message' => "Today's attendance has been reset successfully.",
        ]);
    }

    private function formatAttendance(Attendance $attendance): array
    {
        $calculator = app(AttendanceStatusCalculator::class);

        return [
            'id' => $attendance->id,
            'employee_id' => $attendance->employee_id,
            'attendance_date' => $attendance->attendance_date->toDateString(),
            'date' => $attendance->attendance_date->toDateString(),
            'punch_in_time' => $attendance->punch_in_time,
            'punch_out_time' => $attendance->punch_out_time,
            'punch_in_at' => $this->formatIstDateTime($attendance->punchInAt()),
            'punch_out_at' => $this->formatIstDateTime($attendance->punchOutAt()),
            'punch_in' => $this->formatIstDateTime($attendance->punchInAt()),
            'punch_out' => $this->formatIstDateTime($attendance->punchOutAt()),
            'punch_in_time_ist' => $this->formatIstTime($attendance->punchInAt()),
            'punch_out_time_ist' => $this->formatIstTime($attendance->punchOutAt()),
            'punch_in_latitude' => $attendance->punch_in_latitude,
            'punch_in_longitude' => $attendance->punch_in_longitude,
            'punch_out_latitude' => $attendance->punch_out_latitude,
            'punch_out_longitude' => $attendance->punch_out_longitude,
            'punch_in_location' => $attendance->punch_in_location,
            'punch_out_location' => $attendance->punch_out_location,
            'in_latitude' => $attendance->punch_in_latitude,
            'in_longitude' => $attendance->punch_in_longitude,
            'out_latitude' => $attendance->punch_out_latitude,
            'out_longitude' => $attendance->punch_out_longitude,
            'in_address' => $attendance->punch_in_location,
            'out_address' => $attendance->punch_out_location,
            'punch_in_photo' => $attendance->punch_in_photo,
            'punch_out_photo' => $attendance->punch_out_photo,
            'in_photo' => $this->photoUrl($attendance->punch_in_photo),
            'out_photo' => $this->photoUrl($attendance->punch_out_photo),
            'working_hours' => $attendance->working_hours,
            'working_hours_label' => $calculator->formatWorkingHoursLabel($attendance),
            'total_working_minutes' => $attendance->total_working_minutes,
            'attendance_status' => $attendance->attendance_status,
            'status' => $attendance->attendance_status,
            'approval_status' => $attendance->approval_status,
            'remarks' => $attendance->remarks,
            'timezone' => 'Asia/Kolkata',
        ];
    }

    private function photoUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        return url('storage/'.str_replace('\\', '/', $path));
    }

    private function formatIstDateTime(?\Illuminate\Support\Carbon $value): ?string
    {
        return $value?->timezone(AttendanceCalendar::TIMEZONE)->toIso8601String();
    }

    private function formatIstTime(?\Illuminate\Support\Carbon $value): ?string
    {
        return $value?->timezone(AttendanceCalendar::TIMEZONE)->format('h:i A');
    }
}
