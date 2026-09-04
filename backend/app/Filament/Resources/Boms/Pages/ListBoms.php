<?php

namespace App\Filament\Resources\Boms\Pages;

use App\Enums\BomOutputType;
use App\Filament\Resources\Boms\BomResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListBoms extends ListRecords
{
    protected static string $resource = BomResource::class;

    public function getTabs(): array
    {
        return [
            'manufacturing' => Tab::make('Manufacturing / Semi-Finished')
                ->modifyQueryUsing(fn (Builder $query) => $query->where(
                    'output_type',
                    BomOutputType::SemiFinished->value,
                )),
            'packing' => Tab::make('Packing / Finished Product')
                ->modifyQueryUsing(fn (Builder $query) => $query->where(
                    'output_type',
                    BomOutputType::FinishedProduct->value,
                )),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->authorize(fn (): bool => BomResource::canCreate()),
        ];
    }
}
