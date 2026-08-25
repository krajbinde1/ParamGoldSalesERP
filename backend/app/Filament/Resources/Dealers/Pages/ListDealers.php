<?php

namespace App\Filament\Resources\Dealers\Pages;

use App\Filament\Resources\Dealers\Actions\DownloadDealerImportTemplateAction;
use App\Filament\Resources\Dealers\Actions\ImportDealersAction;
use App\Filament\Resources\Dealers\DealerResource;
use App\Models\Dealer;
use App\Services\Dealers\DealerNetworkOverviewService;
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
use Livewire\Attributes\Url;

class ListDealers extends ListRecords
{
    protected static string $resource = DealerResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    #[Url(as: 'net_state')]
    public ?string $networkState = null;

    #[Url(as: 'net_district')]
    public ?string $networkDistrict = null;

    #[Url(as: 'net_taluka')]
    public ?string $networkTaluka = null;

    #[Url(as: 'net_employee')]
    public int|string|null $networkEmployeeId = null;

    #[Url(as: 'net_type')]
    public ?string $networkDealerType = null;

    public string $networkView = 'chart';

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

    /**
     * @return array<string, mixed>
     */
    public function networkOverview(): array
    {
        $user = auth()->user();
        if ($user === null) {
            return [
                'summary' => [
                    'total_dealers' => 0,
                    'total_districts' => 0,
                    'total_talukas' => 0,
                    'total_villages' => 0,
                ],
                'districts' => [],
                'talukas' => [],
                'areas' => [],
                'markers' => [],
                'has_mappable_dealers' => false,
                'filter_options' => [
                    'states' => [],
                    'districts' => [],
                    'talukas' => [],
                    'employees' => [],
                    'dealer_types' => [],
                ],
                'talukas_are_top_overall' => true,
            ];
        }

        return app(DealerNetworkOverviewService::class)->overview($user, $this->networkFilterPayload());
    }

    public function selectNetworkDistrict(string $district): void
    {
        $district = trim($district);
        if ($district === '') {
            return;
        }

        if ($this->networkDistrict === $district && $this->networkTaluka === null) {
            $this->networkDistrict = null;
        } else {
            $this->networkDistrict = $district;
            $this->networkTaluka = null;
        }

        $this->resetTable();
    }

    public function selectNetworkTaluka(string $taluka, ?string $district = null): void
    {
        $taluka = trim($taluka);
        if ($taluka === '') {
            return;
        }

        if (filled($district)) {
            $this->networkDistrict = trim($district);
        }

        if ($this->networkTaluka === $taluka) {
            $this->networkTaluka = null;
        } else {
            $this->networkTaluka = $taluka;
        }

        $this->resetTable();
    }

    public function resetNetworkFilters(): void
    {
        $this->networkState = null;
        $this->networkDistrict = null;
        $this->networkTaluka = null;
        $this->networkEmployeeId = null;
        $this->networkDealerType = null;
        $this->networkView = 'chart';
        $this->resetTable();
    }

    public function setNetworkView(string $view): void
    {
        $this->networkView = $view === 'map' ? 'map' : 'chart';
    }

    public function updatedNetworkState(): void
    {
        $this->networkState = $this->blankToNull($this->networkState);
        $this->networkDistrict = null;
        $this->networkTaluka = null;
        $this->resetTable();
    }

    public function updatedNetworkDistrict(): void
    {
        $this->networkDistrict = $this->blankToNull($this->networkDistrict);
        $this->networkTaluka = null;
        $this->resetTable();
    }

    public function updatedNetworkTaluka(): void
    {
        $this->networkTaluka = $this->blankToNull($this->networkTaluka);
        $this->resetTable();
    }

    public function updatedNetworkEmployeeId(mixed $value): void
    {
        $this->networkEmployeeId = filled($value) ? (int) $value : null;
        $this->resetTable();
    }

    public function updatedNetworkDealerType(): void
    {
        $this->networkDealerType = $this->blankToNull($this->networkDealerType);
        $this->resetTable();
    }

    /**
     * @param  Builder<Dealer>  $query
     * @return Builder<Dealer>
     */
    private function applyNetworkFilters(Builder $query): Builder
    {
        return app(DealerNetworkOverviewService::class)->applyToQuery($query, $this->networkFilterPayload());
    }

    /**
     * @return array{state: ?string, district: ?string, taluka: ?string, employee_id: ?int, dealer_type: ?string}
     */
    private function networkFilterPayload(): array
    {
        return [
            'state' => $this->blankToNull($this->networkState),
            'district' => $this->blankToNull($this->networkDistrict),
            'taluka' => $this->blankToNull($this->networkTaluka),
            'employee_id' => filled($this->networkEmployeeId) ? (int) $this->networkEmployeeId : null,
            'dealer_type' => $this->blankToNull($this->networkDealerType),
        ];
    }

    private function blankToNull(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return filled($value) ? $value : null;
    }
}
