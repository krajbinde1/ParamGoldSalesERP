<?php

namespace App\Filament\Resources\DealerCreditLimits;

use App\Filament\Resources\DealerCreditLimits\Pages\ListDealerCreditLimits;
use App\Filament\Resources\DealerCreditLimits\Tables\DealerCreditLimitsTable;
use App\Models\Dealer;
use App\Services\Dealers\DealerCreditExposureService;
use App\Services\Dealers\DealerLedgerService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DealerCreditLimitResource extends Resource
{
    protected static ?string $model = Dealer::class;

    protected static ?string $slug = 'dealer-credit-limits';

    protected static ?string $navigationLabel = 'Dealer Credit Limits';

    protected static ?string $modelLabel = 'Dealer Credit Limit';

    protected static ?string $pluralModelLabel = 'Dealer Credit Limits';

    protected static string|\UnitEnum|null $navigationGroup = 'Sales Operations';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $recordTitleAttribute = 'firm_name';

    public static function table(Table $table): Table
    {
        return DealerCreditLimitsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDealerCreditLimits::route('/'),
        ];
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->isAdminUser() || $user->isDirectorUser());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        app(DealerLedgerService::class)->scopeWithCurrentOutstanding($query);
        app(DealerCreditExposureService::class)->scopeWithPendingExposure($query);

        return $query->with([
            'assignedEmployee.reportingManager',
            'creditLimit.updatedBy:id,name',
        ]);
    }
}
