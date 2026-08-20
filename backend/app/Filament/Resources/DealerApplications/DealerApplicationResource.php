<?php

namespace App\Filament\Resources\DealerApplications;

use App\Filament\Resources\DealerApplications\Pages\ListDealerApplications;
use App\Filament\Resources\DealerApplications\Pages\ViewDealerApplication;
use App\Filament\Resources\DealerApplications\Schemas\DealerApplicationInfolist;
use App\Filament\Resources\DealerApplications\Tables\DealerApplicationsTable;
use App\Filament\Resources\DealerApplications\Widgets\DealerApplicationStats;
use App\Models\DealerApplication;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DealerApplicationResource extends Resource
{
    protected static ?string $model = DealerApplication::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Sales Operations';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Dealer Approvals';

    protected static ?string $modelLabel = 'Dealer Application';

    protected static ?string $pluralModelLabel = 'Dealer Approvals';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $recordTitleAttribute = 'firm_name';

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdminUser() ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function infolist(Schema $schema): Schema
    {
        return DealerApplicationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DealerApplicationsTable::configure($table);
    }

    public static function getWidgets(): array
    {
        return [
            DealerApplicationStats::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDealerApplications::route('/'),
            'view' => ViewDealerApplication::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['employee.reportingManager', 'dealer', 'party'])
            ->latest('id');
    }
}
