<?php

namespace App\Filament\Support;

use Closure;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;

final class MaterialMasterStockColumns
{
    /**
     * @param  Closure(Model): string  $colorUsing
     */
    public static function availableStock(string $attribute, Closure $colorUsing): TextColumn
    {
        return TextColumn::make($attribute)
            ->label('Available Stock')
            ->numeric(3)
            ->sortable()
            ->color($colorUsing);
    }

    /**
     * @param  Closure(): bool  $visible
     * @param  Closure(Model): float|null  $state
     * @param  Closure(mixed, string): void|null  $sortQuery
     */
    public static function stockValue(
        string $attribute,
        Closure $visible,
        ?Closure $state = null,
        ?Closure $sortQuery = null,
    ): TextColumn {
        $column = TextColumn::make($attribute)
            ->label('Stock Value')
            ->money('INR')
            ->visible($visible);

        if ($state !== null) {
            $column->state($state);
        }

        if ($sortQuery !== null) {
            $column->sortable(query: $sortQuery);
        } else {
            $column->sortable();
        }

        return $column;
    }

    /**
     * @param  Closure(): bool  $visible
     * @param  Closure(Model): string  $unitUsing
     */
    public static function averageStockRate(
        string $attribute,
        Closure $visible,
        Closure $unitUsing,
    ): TextColumn {
        return TextColumn::make($attribute)
            ->label('Average Stock Rate')
            ->money('INR')
            ->suffix(fn (Model $record): string => filled($unitUsing($record)) ? '/'.$unitUsing($record) : '')
            ->visible($visible)
            ->sortable();
    }
}
