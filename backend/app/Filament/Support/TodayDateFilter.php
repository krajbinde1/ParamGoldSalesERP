<?php

namespace App\Filament\Support;

use App\Support\AttendanceCalendar;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class TodayDateFilter
{
    public static function make(string $column, string $label = 'Date'): Filter
    {
        return Filter::make($column)
            ->label($label)
            ->schema([
                DatePicker::make('date')
                    ->label($label)
                    ->native(false),
            ])
            ->query(function (Builder $query, array $data) use ($column): Builder {
                return $query->when(
                    filled($data['date'] ?? null),
                    fn (Builder $builder): Builder => $builder->whereDate($column, $data['date']),
                );
            })
            ->indicateUsing(function (array $data) use ($label): ?string {
                if (! filled($data['date'] ?? null)) {
                    return null;
                }

                $date = Carbon::parse($data['date'], AttendanceCalendar::TIMEZONE);
                $value = $date->isSameDay(AttendanceCalendar::today())
                    ? 'Today'
                    : $date->format('d M Y');

                return $label.': '.$value;
            });
    }
}
