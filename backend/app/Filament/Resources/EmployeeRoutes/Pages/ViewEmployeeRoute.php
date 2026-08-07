<?php

namespace App\Filament\Resources\EmployeeRoutes\Pages;

use App\Filament\Resources\EmployeeRoutes\EmployeeRouteResource;
use App\Models\Attendance;
use App\Services\EmployeeRouteAnalysisService;
use App\Support\AttendanceCalendar;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewEmployeeRoute extends ViewRecord
{
    protected static string $resource = EmployeeRouteResource::class;

    protected string $view = 'filament.resources.employee-routes.view-employee-route';

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
        return $this->getTitle();
    }

    public function getSubheading(): string|Htmlable|null
    {
        /** @var Attendance $record */
        $record = $this->getRecord();

        return $record->attendance_date
            ->timezone(AttendanceCalendar::TIMEZONE)
            ->format('d M Y');
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

        return [
            'summary' => $analysis['summary'],
            'diagnostics' => $analysis['diagnostics'],
            'valid_points' => $validPoints,
            'route_points' => $service->formatRoutePointsForResponse($record),
            'timeline' => $analysis['timeline'],
            'stops' => $analysis['stops'],
            'punch_in' => [
                'time' => $record->punchInAt()?->timezone(AttendanceCalendar::TIMEZONE)->format('d M Y h:i A'),
                'latitude' => $record->punch_in_latitude !== null ? (float) $record->punch_in_latitude : null,
                'longitude' => $record->punch_in_longitude !== null ? (float) $record->punch_in_longitude : null,
                'location' => $record->punch_in_location,
            ],
            'punch_out' => [
                'time' => $record->punchOutAt()?->timezone(AttendanceCalendar::TIMEZONE)->format('d M Y h:i A'),
                'latitude' => $record->punch_out_latitude !== null ? (float) $record->punch_out_latitude : null,
                'longitude' => $record->punch_out_longitude !== null ? (float) $record->punch_out_longitude : null,
                'location' => $record->punch_out_location,
            ],
            'employee' => [
                'full_name' => $record->employee?->full_name,
                'employee_code' => $record->employee?->employee_code,
            ],
            'attendance_date' => $record->attendance_date->timezone(AttendanceCalendar::TIMEZONE)->format('d M Y'),
        ];
    }
}
