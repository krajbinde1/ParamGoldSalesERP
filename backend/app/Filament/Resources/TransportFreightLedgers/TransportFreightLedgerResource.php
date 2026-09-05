<?php

namespace App\Filament\Resources\TransportFreightLedgers;

use App\Filament\Concerns\InventoryFilamentAccess;
use App\Filament\Resources\TransportFreightLedgers\Pages\ListTransportFreightLedgers;
use App\Filament\Resources\TransportFreightLedgers\Pages\ViewTransportFreightLedger;
use App\Filament\Resources\TransportFreightLedgers\Schemas\TransportFreightLedgerInfolist;
use App\Filament\Resources\TransportFreightLedgers\Tables\TransportFreightLedgersTable;
use App\Models\TransportFreightLedger;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TransportFreightLedgerResource extends Resource
{
    use InventoryFilamentAccess;

    protected static ?string $model = TransportFreightLedger::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory & Manufacturing';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Transport/Freight Charges';

    protected static ?string $modelLabel = 'Transport/Freight Charge';

    protected static ?string $pluralModelLabel = 'Transport/Freight Charges';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $recordTitleAttribute = 'purchase_number';

    public static function infolist(Schema $schema): Schema
    {
        return TransportFreightLedgerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TransportFreightLedgersTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'purchase:id,purchase_number,status',
            'supplier:id,supplier_name',
            'createdBy:id,name',
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTransportFreightLedgers::route('/'),
            'view' => ViewTransportFreightLedger::route('/{record}'),
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

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
