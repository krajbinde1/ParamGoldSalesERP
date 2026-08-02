<?php

namespace App\Filament\Resources\StockLedgers;

use App\Filament\Concerns\InventoryFilamentAccess;
use App\Filament\Resources\StockLedgers\Pages\ListStockLedgers;
use App\Filament\Resources\StockLedgers\Pages\ViewStockLedger;
use App\Filament\Resources\StockLedgers\Schemas\StockLedgerInfolist;
use App\Filament\Resources\StockLedgers\Tables\StockLedgersTable;
use App\Models\StockLedger;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class StockLedgerResource extends Resource
{
    use InventoryFilamentAccess;

    protected static ?string $model = StockLedger::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory & Manufacturing';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Stock Ledger (Entries)';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $recordTitleAttribute = 'reference_number';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return StockLedgerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StockLedgersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockLedgers::route('/'),
            'view' => ViewStockLedger::route('/{record}'),
        ];
    }

    /**
     * Stock ledger entries are system-generated and never editable directly.
     */
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

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
