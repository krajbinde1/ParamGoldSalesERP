<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\InteractsWithDealerNetworkOverview;
use App\Filament\Resources\Dealers\DealerResource;
use App\Filament\Resources\Dealers\Tables\DealersTable;
use App\Models\Dealer;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DealerNetwork extends Page implements HasTable
{
    use InteractsWithDealerNetworkOverview;
    use InteractsWithTable;

    protected static string|\UnitEnum|null $navigationGroup = 'Sales Operations';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Dealer Network';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?string $title = 'Dealer Network';

    protected static ?string $slug = 'dealer-network';

    protected string $view = 'filament.pages.dealer-network';

    protected Width|string|null $maxContentWidth = Width::Full;

    public static function canAccess(): bool
    {
        return DealerResource::canAccess();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function table(Table $table): Table
    {
        return DealersTable::configure($table)
            ->query(fn (): Builder => $this->dealersQuery());
    }

    /**
     * @return Builder<Dealer>
     */
    private function dealersQuery(): Builder
    {
        return $this->applyNetworkFilters(DealerResource::getEloquentQuery());
    }
}
