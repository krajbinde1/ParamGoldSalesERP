<?php

namespace App\Filament\Support;

use App\Models\Dealer;
use App\Services\Dealers\DealerAccessService;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;

final class DealerSelect
{
    public static function scopeVisibleDealers(Builder $query): Builder
    {
        $user = auth()->user();

        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        return app(DealerAccessService::class)->scopeVisibleTo(
            $query->where('status', true),
            $user,
        );
    }

    public static function applyRelationshipSelect(Select $select): Select
    {
        return $select->relationship(
            'dealer',
            'firm_name',
            fn (Builder $query): Builder => self::scopeVisibleDealers($query),
        );
    }
}
