<?php

namespace App\Filament\Resources\DealerApplications\Widgets;

use App\Models\DealerApplication;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DealerApplicationStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make(
                'Pending Manager Approval',
                DealerApplication::query()->where('status', DealerApplication::STATUS_PENDING_MANAGER)->count(),
            )->color('warning'),
            Stat::make(
                'Pending Admin Approval',
                DealerApplication::query()->where('status', DealerApplication::STATUS_PENDING_ADMIN)->count(),
            )->color('info'),
            Stat::make(
                'Approved Dealers',
                DealerApplication::query()->where('status', DealerApplication::STATUS_APPROVED)->count(),
            )->color('success'),
            Stat::make(
                'Rejected Applications',
                DealerApplication::query()->where('status', DealerApplication::STATUS_REJECTED)->count(),
            )->color('danger'),
        ];
    }
}
