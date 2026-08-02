<?php

namespace App\Filament\Resources\EmployeeRoutes\Tables;

use App\Filament\Resources\EmployeeRoutes\EmployeeRouteResource;
use App\Filament\Support\EmployeeSelect;
use App\Models\Attendance;
use App\Services\EmployeeRouteAnalysisService;
use App\Support\AttendanceCalendar;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EmployeeRoutesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('attendance_date', 'desc')
            ->columns([
                TextColumn::make('employee.full_name')
                    ->label('Employee')
                    ->formatStateUsing(fn (Attendance $record): string => $record->employee?->displayLabel() ?? '-')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('attendance_date')
                    ->label('Attendance Date')
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('punch_in_time')
                    ->label('Punch In')
                    ->formatStateUsing(fn (Attendance $record): string => $record->punchInAt()?->timezone(AttendanceCalendar::TIMEZONE)->format('h:i A') ?? '-')
                    ->placeholder('-'),
                TextColumn::make('punch_out_time')
                    ->label('Punch Out')
                    ->formatStateUsing(fn (Attendance $record): string => $record->punchOutAt()?->timezone(AttendanceCalendar::TIMEZONE)->format('h:i A') ?? '-')
                    ->placeholder('-'),
                TextColumn::make('total_distance_km')
                    ->label('Total KM')
                    ->state(function (Attendance $record): string {
                        if ($record->total_route_distance_km !== null) {
                            return number_format((float) $record->total_route_distance_km, 2);
                        }

                        $analysis = app(EmployeeRouteAnalysisService::class)->analyze($record);

                        return number_format($analysis['summary']['total_distance_km'], 2);
                    }),
                TextColumn::make('route_points_count')
                    ->label('Route Points')
                    ->counts('routePoints')
                    ->sortable(),
                TextColumn::make('stop_count')
                    ->label('Stops')
                    ->state(function (Attendance $record): string {
                        $analysis = app(EmployeeRouteAnalysisService::class)->analyze($record);

                        return (string) $analysis['summary']['stop_count'];
                    }),
                TextColumn::make('view_route')
                    ->label('View Route')
                    ->state('View Route')
                    ->url(fn (Attendance $record): string => EmployeeRouteResource::getUrl('view', ['record' => $record]))
                    ->color('primary'),
            ])
            ->filters([
                SelectFilter::make('employee_id')
                    ->label('Employee')
                    ->relationship('employee', 'full_name')
                    ->tap(fn (SelectFilter $filter) => EmployeeSelect::applyRelationshipFilter($filter))
                    ->preload(),
                Filter::make('attendance_date')
                    ->label('Date')
                    ->schema([
                        DatePicker::make('date')
                            ->label('Date')
                            ->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            filled($data['date'] ?? null),
                            fn (Builder $builder): Builder => $builder->whereDate('attendance_date', $data['date']),
                        );
                    }),
                SelectFilter::make('month')
                    ->label('Month')
                    ->options(self::monthOptions())
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            filled($data['value'] ?? null),
                            fn (Builder $builder): Builder => $builder->whereMonth('attendance_date', (int) $data['value']),
                        );
                    }),
                SelectFilter::make('year')
                    ->label('Year')
                    ->options(self::yearOptions())
                    ->default(AttendanceCalendar::now()->year)
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            filled($data['value'] ?? null),
                            fn (Builder $builder): Builder => $builder->whereYear('attendance_date', (int) $data['value']),
                        );
                    }),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('View Route'),
                Action::make('viewRoute')
                    ->label('View Route')
                    ->icon('heroicon-o-map')
                    ->url(fn (Attendance $record): string => EmployeeRouteResource::getUrl('view', ['record' => $record])),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function monthOptions(): array
    {
        return collect(range(1, 12))
            ->mapWithKeys(fn (int $month): array => [$month => AttendanceCalendar::now()->month($month)->format('F')])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function yearOptions(): array
    {
        $currentYear = AttendanceCalendar::now()->year;

        return collect(range($currentYear - 2, $currentYear))
            ->mapWithKeys(fn (int $year): array => [$year => (string) $year])
            ->all();
    }
}
