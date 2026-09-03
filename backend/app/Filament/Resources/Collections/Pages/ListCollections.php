<?php

namespace App\Filament\Resources\Collections\Pages;

use App\Filament\Resources\Collections\CollectionResource;
use App\Filament\Widgets\AdminDirectorCollectionOutstandingWidget;
use Filament\Resources\Pages\ListRecords;

class ListCollections extends ListRecords
{
    protected static string $resource = CollectionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            AdminDirectorCollectionOutstandingWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
