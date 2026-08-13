<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class EmployeeTaskController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;
        $today = today()->toDateString();
        $filter = $request->query('filter', 'today');

        if (! in_array($filter, ['today', 'upcoming', 'overdue', 'completed'], true)) {
            $filter = 'today';
        }

        $base = EmployeeTask::query()->where('employee_id', $employee->id);

        $todayPendingCount = (clone $base)
            ->whereDate('due_date', $today)
            ->where('is_completed', false)
            ->count();

        $todayCompletedCount = (clone $base)
            ->whereDate('due_date', $today)
            ->where('is_completed', true)
            ->count();

        $overdueCount = (clone $base)
            ->whereDate('due_date', '<', $today)
            ->where('is_completed', false)
            ->count();

        $counts = [
            'today_pending' => $todayPendingCount,
            'today_completed' => $todayCompletedCount,
            'overdue_count' => $overdueCount,
        ];

        if ($filter === 'today') {
            $pending = (clone $base)
                ->whereDate('due_date', $today)
                ->where('is_completed', false)
                ->orderByDesc('is_important')
                ->orderBy('due_time')
                ->orderBy('id')
                ->get()
                ->map(fn (EmployeeTask $task): array => $this->formatTask($task))
                ->values();

            $completed = (clone $base)
                ->whereDate('due_date', $today)
                ->where('is_completed', true)
                ->orderByDesc('completed_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn (EmployeeTask $task): array => $this->formatTask($task))
                ->values();

            $overdue = (clone $base)
                ->whereDate('due_date', '<', $today)
                ->where('is_completed', false)
                ->orderBy('due_date')
                ->orderBy('due_time')
                ->orderBy('id')
                ->get()
                ->map(fn (EmployeeTask $task): array => $this->formatTask($task))
                ->values();

            return response()->json([
                'filter' => 'today',
                'counts' => $counts,
                'pending' => $pending,
                'completed' => $completed,
                'overdue' => $overdue,
            ]);
        }

        if ($filter === 'upcoming') {
            $tasks = (clone $base)
                ->whereDate('due_date', '>', $today)
                ->where('is_completed', false)
                ->orderBy('due_date')
                ->orderBy('due_time')
                ->orderBy('id')
                ->get()
                ->map(fn (EmployeeTask $task): array => $this->formatTask($task))
                ->values();

            return response()->json([
                'filter' => 'upcoming',
                'counts' => $counts,
                'tasks' => $tasks,
            ]);
        }

        if ($filter === 'overdue') {
            $tasks = (clone $base)
                ->whereDate('due_date', '<', $today)
                ->where('is_completed', false)
                ->orderBy('due_date')
                ->orderBy('due_time')
                ->orderBy('id')
                ->get()
                ->map(fn (EmployeeTask $task): array => $this->formatTask($task))
                ->values();

            return response()->json([
                'filter' => 'overdue',
                'counts' => $counts,
                'tasks' => $tasks,
            ]);
        }

        $tasks = (clone $base)
            ->where('is_completed', true)
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (EmployeeTask $task): array => $this->formatTask($task))
            ->values();

        return response()->json([
            'filter' => 'completed',
            'counts' => $counts,
            'tasks' => $tasks,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
            'due_date' => ['nullable', 'date'],
            'due_time' => ['nullable', 'date_format:H:i'],
            'is_important' => ['sometimes', 'boolean'],
            'reminder_at' => ['nullable', 'date'],
        ]);

        $task = EmployeeTask::query()->create([
            'employee_id' => $employee->id,
            'title' => trim($validated['title']),
            'note' => isset($validated['note']) ? trim((string) $validated['note']) : null,
            'due_date' => $validated['due_date'] ?? today()->toDateString(),
            'due_time' => $validated['due_time'] ?? null,
            'is_important' => (bool) ($validated['is_important'] ?? false),
            'is_completed' => false,
            'completed_at' => null,
            'reminder_at' => $validated['reminder_at'] ?? null,
        ]);

        return response()->json([
            'message' => 'Task created successfully.',
            'data' => $this->formatTask($task),
        ], 201);
    }

    public function show(Request $request, EmployeeTask $task): JsonResponse
    {
        $this->authorizeTask($request, $task);

        return response()->json([
            'data' => $this->formatTask($task),
        ]);
    }

    public function update(Request $request, EmployeeTask $task): JsonResponse
    {
        $this->authorizeTask($request, $task);

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
            'due_date' => ['sometimes', 'required', 'date'],
            'due_time' => ['nullable', 'date_format:H:i'],
            'is_important' => ['sometimes', 'boolean'],
            'is_completed' => ['sometimes', 'boolean'],
            'reminder_at' => ['nullable', 'date'],
        ]);

        if (array_key_exists('title', $validated)) {
            $task->title = trim($validated['title']);
        }

        if (array_key_exists('note', $validated)) {
            $task->note = filled($validated['note']) ? trim((string) $validated['note']) : null;
        }

        if (array_key_exists('due_date', $validated)) {
            $task->due_date = $validated['due_date'];
        }

        if (array_key_exists('due_time', $validated)) {
            $task->due_time = $validated['due_time'];
        }

        if (array_key_exists('is_important', $validated)) {
            $task->is_important = (bool) $validated['is_important'];
        }

        if (array_key_exists('reminder_at', $validated)) {
            $task->reminder_at = $validated['reminder_at'];
        }

        if (array_key_exists('is_completed', $validated)) {
            $isCompleted = (bool) $validated['is_completed'];
            $task->is_completed = $isCompleted;
            $task->completed_at = $isCompleted
                ? ($task->completed_at ?? now())
                : null;
        }

        $task->save();

        return response()->json([
            'message' => 'Task updated successfully.',
            'data' => $this->formatTask($task->fresh()),
        ]);
    }

    public function destroy(Request $request, EmployeeTask $task): JsonResponse
    {
        $this->authorizeTask($request, $task);

        $task->delete();

        return response()->json([
            'message' => 'Task deleted successfully.',
        ]);
    }

    public function complete(Request $request, EmployeeTask $task): JsonResponse
    {
        $this->authorizeTask($request, $task);

        $task->update([
            'is_completed' => true,
            'completed_at' => now(),
        ]);

        return response()->json([
            'message' => 'Task marked as completed.',
            'data' => $this->formatTask($task->fresh()),
        ]);
    }

    public function incomplete(Request $request, EmployeeTask $task): JsonResponse
    {
        $this->authorizeTask($request, $task);

        $task->update([
            'is_completed' => false,
            'completed_at' => null,
        ]);

        return response()->json([
            'message' => 'Task marked as incomplete.',
            'data' => $this->formatTask($task->fresh()),
        ]);
    }

    public function moveToTomorrow(Request $request, EmployeeTask $task): JsonResponse
    {
        $this->authorizeTask($request, $task);

        $task->update([
            'due_date' => today()->addDay()->toDateString(),
            'is_completed' => false,
            'completed_at' => null,
        ]);

        return response()->json([
            'message' => 'Task moved to tomorrow.',
            'data' => $this->formatTask($task->fresh()),
        ]);
    }

    private function authorizeTask(Request $request, EmployeeTask $task): void
    {
        if ($task->employee_id !== $request->user()->employee->id) {
            abort(403, 'You are not allowed to access this task.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formatTask(EmployeeTask $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'note' => $task->note,
            'due_date' => $task->due_date?->toDateString(),
            'due_time' => $this->formatDueTime($task),
            'is_important' => (bool) $task->is_important,
            'is_completed' => (bool) $task->is_completed,
            'completed_at' => $task->completed_at?->toIso8601String(),
            'reminder_at' => $task->reminder_at?->toIso8601String(),
            'created_at' => $task->created_at?->toIso8601String(),
            'updated_at' => $task->updated_at?->toIso8601String(),
        ];
    }

    private function formatDueTime(EmployeeTask $task): ?string
    {
        if (blank($task->due_time)) {
            return null;
        }

        if ($task->due_time instanceof Carbon) {
            return $task->due_time->format('H:i');
        }

        return Carbon::parse((string) $task->due_time)->format('H:i');
    }
}
