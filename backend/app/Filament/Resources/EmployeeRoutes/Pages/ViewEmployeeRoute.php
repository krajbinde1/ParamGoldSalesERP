<?php

namespace App\Filament\Resources\EmployeeRoutes\Pages;

use App\Filament\Resources\EmployeeRoutes\EmployeeRouteResource;
use App\Models\Attendance;
use App\Services\EmployeeRouteAnalysisService;
use App\Support\AttendanceCalendar;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;

class ViewEmployeeRoute extends ViewRecord
{
    protected static string $resource = EmployeeRouteResource::class;

    protected string $view = 'filament.resources.employee-routes.view-employee-route';

    protected Width | string | null $maxContentWidth = Width::Full;

    protected bool $hasTopbar = false;

    public function getTitle(): string|Htmlable
    {
        /** @var Attendance $record */
        $record = $this->getRecord();

        return sprintf(
            'Employee Route — %s',
            $record->employee?->full_name ?? 'Employee',
        );
    }

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return null;
    }

    /**
     * @return array<string>
     */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtraBodyAttributes(): array
    {
        return [
            'class' => 'er-fullscreen-route',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getRouteMapData(): array
    {
        /** @var Attendance $record */
        $record = $this->getRecord();

        $service = app(EmployeeRouteAnalysisService::class);
        $analysis = $service->analyze($record);
        $validPoints = $service->formatValidPointsForMap($analysis['valid_points']);

        $previous = $this->findAdjacentAttendance('previous');
        $next = $this->findAdjacentAttendance('next');

        return [
            'summary' => $analysis['summary'],
            'diagnostics' => $analysis['diagnostics'],
            'valid_points' => $validPoints,
            'route_points' => $service->formatRoutePointsForResponse($record),
            'timeline' => $analysis['timeline'],
            'stops' => $analysis['stops'],
            'journey_events' => $analysis['journey_events'],
            'punch_in' => [
                'time' => $record->punchInAt()?->timezone(AttendanceCalendar::TIMEZONE)->format('h:i A'),
                'full_time' => $record->punchInAt()?->timezone(AttendanceCalendar::TIMEZONE)->format('d M Y h:i A'),
                'latitude' => $record->punch_in_latitude !== null ? (float) $record->punch_in_latitude : null,
                'longitude' => $record->punch_in_longitude !== null ? (float) $record->punch_in_longitude : null,
                'location' => $record->punch_in_location,
            ],
            'punch_out' => [
                'time' => $record->punchOutAt()?->timezone(AttendanceCalendar::TIMEZONE)->format('h:i A'),
                'full_time' => $record->punchOutAt()?->timezone(AttendanceCalendar::TIMEZONE)->format('d M Y h:i A'),
                'latitude' => $record->punch_out_latitude !== null ? (float) $record->punch_out_latitude : null,
                'longitude' => $record->punch_out_longitude !== null ? (float) $record->punch_out_longitude : null,
                'location' => $record->punch_out_location,
            ],
            'employee' => [
                'id' => $record->employee_id,
                'full_name' => $record->employee?->full_name,
                'employee_code' => $record->employee?->employee_code,
            ],
            'attendance_date' => $record->attendance_date->timezone(AttendanceCalendar::TIMEZONE)->format('d M Y'),
            'attendance_date_iso' => $record->attendance_date->timezone(AttendanceCalendar::TIMEZONE)->toDateString(),
            'navigation' => [
                'previous_url' => $previous !== null
                    ? EmployeeRouteResource::getUrl('view', ['record' => $previous])
                    : null,
                'next_url' => $next !== null
                    ? EmployeeRouteResource::getUrl('view', ['record' => $next])
                    : null,
                'list_url' => EmployeeRouteResource::getUrl('index'),
            ],
        ];
    }

    public function goToDate(string $date): void
    {
        /** @var Attendance $record */
        $record = $this->getRecord();

        $target = Attendance::query()
            ->where('employee_id', $record->employee_id)
            ->whereDate('attendance_date', $date)
            ->whereNotNull('punch_in_time')
            ->orderByDesc('id')
            ->first();

        if ($target === null) {
            $this->dispatch('route-date-missing', date: $date);

            return;
        }

        $this->redirect(EmployeeRouteResource::getUrl('view', ['record' => $target]));
    }

    private function findAdjacentAttendance(string $direction): ?Attendance
    {
        /** @var Attendance $record */
        $record = $this->getRecord();

        $query = Attendance::query()
            ->where('employee_id', $record->employee_id)
            ->whereNotNull('punch_in_time');

        if ($direction === 'previous') {
            return $query
                ->whereDate('attendance_date', '<', $record->attendance_date->toDateString())
                ->orderByDesc('attendance_date')
                ->orderByDesc('id')
                ->first();
        }

        return $query
            ->whereDate('attendance_date', '>', $record->attendance_date->toDateString())
            ->orderBy('attendance_date')
            ->orderBy('id')
            ->first();
    }
}
