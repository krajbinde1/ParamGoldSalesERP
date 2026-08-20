<?php

namespace App\Filament\Resources\Farmers\Pages;

use App\Filament\Resources\Farmers\FarmerResource;
use Filament\Resources\Pages\ViewRecord;

class ViewFarmer extends ViewRecord
{
    protected static string $resource = FarmerResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->getRecord()->load([
            'district',
            'taluka',
            'createdByEmployee',
            'fieldActivities' => fn ($query) => $query->orderByDesc('activity_date')->orderByDesc('id'),
            'fieldActivities.employee',
            'fieldActivities.crop',
            'fieldActivities.recommendations.product',
        ]);
    }
}
