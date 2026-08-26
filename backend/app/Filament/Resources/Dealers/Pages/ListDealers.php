<?php

namespace App\Filament\Resources\Dealers\Pages;

use App\Filament\Concerns\InteractsWithDealerNetworkOverview;
use App\Filament\Resources\Dealers\Actions\DownloadDealerImportTemplateAction;
use App\Filament\Resources\Dealers\Actions\ImportDealersAction;
use App\Filament\Resources\Dealers\DealerResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;

class ListDealers extends ListRecords
{
    use InteractsWithDealerNetworkOverview;

    protected static string $resource = DealerResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('tallyImportHistory')
                ->label('Tally Import History')
                ->url(DealerResource::getUrl('tally-import-history'))
                ->color('gray')
                ->visible(fn (): bool => (auth()->user()?->isAdminUser() ?? false) || (auth()->user()?->isDirectorUser() ?? false)),
            DownloadDealerImportTemplateAction::make()
                ->visible(fn (): bool => DealerResource::canCreate()),
            ImportDealersAction::make()
                ->visible(fn (): bool => DealerResource::canCreate()),
            CreateAction::make()
                ->authorize(fn (): bool => DealerResource::canCreate()),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getTabsContentComponent(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                SchemaView::make('filament.resources.dealers.pages.partials.dealer-network-overview'),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $this->applyNetworkFilters($query));
    }
}
