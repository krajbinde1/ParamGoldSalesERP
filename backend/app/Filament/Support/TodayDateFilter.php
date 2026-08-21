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
                    ->native(false)
                    ->format('Y-m-d')
                    ->displayFormat('d M Y')
                    ->timezone(AttendanceCalendar::TIMEZONE),
            ])
            ->query(function (Builder $query, array $data) use ($column): Builder {
                $date = self::normalizeDate($data['date'] ?? null);

                return $query->when(
                    filled($date),
                    fn (Builder $builder): Builder => $builder->whereDate($column, $date),
                );
            })
            ->indicateUsing(function (array $data) use ($label): ?string {
                $date = self::normalizeDate($data['date'] ?? null);

                if ($date === null) {
                    return null;
                }

                $parsed = Carbon::parse($date, AttendanceCalendar::TIMEZONE);
                $value = $parsed->isSameDay(AttendanceCalendar::today())
                    ? 'Today'
                    : $parsed->format('d M Y');

                return $label.': '.$value;
            });
    }

    /**
     * Filament's non-native DatePicker stores state as `Y-m-d H:i:s`.
     * Always reduce that to an IST calendar date before querying.
     */
    public static function normalizeDate(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return Carbon::parse($value, AttendanceCalendar::TIMEZONE)->toDateString();
    }
}
