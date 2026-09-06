<?php

namespace App\Filament\Resources\ProductionBatches\Schemas;

use App\Models\ProductionBatch;
use App\Services\Inventory\ProductionBatchSheetPresenter;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class ProductionBatchInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextEntry::make('batch_layout')
                    ->hiddenLabel()
                    ->html()
                    ->state(fn (ProductionBatch $record): string => (string) $record->batch_number)
                    ->formatStateUsing(function ($state, ProductionBatch $record): HtmlString {
                        $showCosts = auth()->user()?->canViewProductionCosts() ?? false;

                        return new HtmlString(
                            view('filament.resources.production-batches.partials.production-batch-view', [
                                'record' => $record,
                                'sheet' => ProductionBatchSheetPresenter::for($record, $showCosts),
                            ])->render()
                        );
                    })
                    ->columnSpanFull(),
            ]);
    }
}
