<?php

namespace App\Filament\Resources\Farmers\Widgets;

use App\Models\Farmer;
use App\Models\FieldActivity;
use App\Models\FieldActivityRecommendation;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FarmerStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $today = now('Asia/Kolkata')->toDateString();
        $monthStart = now('Asia/Kolkata')->startOfMonth()->toDateString();

        return [
            Stat::make('Total Farmers', Farmer::query()->count())->color('success'),
            Stat::make('New Farmers Today', Farmer::query()->whereDate('created_at', $today)->count())->color('info'),
            Stat::make('New Farmers This Month', Farmer::query()->whereDate('created_at', '>=', $monthStart)->count())->color('warning'),
            Stat::make('Total Field Activities', FieldActivity::query()->count())->color('primary'),
            Stat::make(
                'Product Recommendations',
                FieldActivityRecommendation::query()->count(),
            )->color('gray'),
        ];
    }
}
