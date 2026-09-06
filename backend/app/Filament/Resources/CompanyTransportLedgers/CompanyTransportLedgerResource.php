<?php

namespace App\Filament\Resources\CompanyTransportLedgers;

use App\Filament\Resources\CompanyTransportLedgers\Pages\CreateCompanyTransportLedger;
use App\Filament\Resources\CompanyTransportLedgers\Pages\EditCompanyTransportLedger;
use App\Filament\Resources\CompanyTransportLedgers\Pages\ListCompanyTransportLedgers;
use App\Filament\Resources\CompanyTransportLedgers\Pages\ViewCompanyTransportLedger;
use App\Filament\Resources\CompanyTransportLedgers\Schemas\CompanyTransportLedgerForm;
use App\Filament\Resources\CompanyTransportLedgers\Schemas\CompanyTransportLedgerInfolist;
use App\Filament\Resources\CompanyTransportLedgers\Tables\CompanyTransportLedgersTable;
use App\Models\CompanyTransportLedgerEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CompanyTransportLedgerResource extends Resource
{
    protected static ?string $model = CompanyTransportLedgerEntry::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Sales Operations';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Company Transport Ledger';

    protected static ?string $modelLabel = 'Company Transport Entry';

    protected static ?string $pluralModelLabel = 'Company Transport Ledger';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $recordTitleAttribute = 'particulars';

    protected static ?string $slug = 'company-transport-ledger';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if ($user?->hasOrdersOnlyFilamentAccess() && ! $user->isAdminUser() && ! $user->isDirectorUser()) {
            return false;
        }

        return $user?->can('viewAny', CompanyTransportLedgerEntry::class) ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function form(Schema $schema): Schema
    {
        return CompanyTransportLedgerForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CompanyTransportLedgerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CompanyTransportLedgersTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'enteredBy:id,name',
            'updatedBy:id,name',
            'order:id,order_no',
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompanyTransportLedgers::route('/'),
            'create' => CreateCompanyTransportLedger::route('/create'),
            'view' => ViewCompanyTransportLedger::route('/{record}'),
            'edit' => EditCompanyTransportLedger::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', CompanyTransportLedgerEntry::class) ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof CompanyTransportLedgerEntry
            && (auth()->user()?->can('update', $record) ?? false);
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
