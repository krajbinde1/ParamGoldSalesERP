<?php

namespace App\Filament\Resources\DealerCreditLimits\Pages;

use App\Filament\Resources\DealerCreditLimits\DealerCreditLimitResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListDealerCreditLimits extends ListRecords
{
    protected static string $resource = DealerCreditLimitResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
