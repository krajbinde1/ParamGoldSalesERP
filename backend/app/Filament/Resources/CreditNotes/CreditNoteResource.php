<?php

namespace App\Filament\Resources\CreditNotes;

use App\Filament\Resources\CreditNotes\Pages\CreateCreditNote;
use App\Filament\Resources\CreditNotes\Pages\EditCreditNote;
use App\Filament\Resources\CreditNotes\Pages\ListCreditNotes;
use App\Filament\Resources\CreditNotes\Pages\ViewCreditNote;
use App\Filament\Resources\CreditNotes\Schemas\CreditNoteForm;
use App\Filament\Resources\CreditNotes\Schemas\CreditNoteInfolist;
use App\Filament\Resources\CreditNotes\Tables\CreditNotesTable;
use App\Models\CreditNote;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CreditNoteResource extends Resource
{
    protected static ?string $model = CreditNote::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Sales Operations';

    protected static ?int $navigationSort = 5;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $recordTitleAttribute = 'credit_note_no';

    protected static ?string $navigationLabel = 'Credit Notes';

    protected static ?string $pluralModelLabel = 'Credit Notes';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if ($user instanceof User && ($user->hasProductionManagerJobRole() || $user->hasProductionSupervisorJobRole() || $user->hasRole(\App\Enums\UserRole::ProductionSupervisor))) {
            return true;
        }

        if ($user?->hasOrdersOnlyFilamentAccess() ?? false) {
            return false;
        }

        return parent::canAccess();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with([
            'dealer:id,firm_name,dealer_code',
            'destinationDealer:id,firm_name,dealer_code',
            'linkedOrder:id,order_no,status,dealer_id',
            'salesEmployee:id,full_name,employee_code',
            'items.product:id,product_name,product_code',
        ]);

        $user = auth()->user();
        if ($user instanceof User && ! $user->isAdminUser() && ! $user->isDirectorUser()
            && ($user->hasProductionManagerJobRole() || $user->hasProductionSupervisorJobRole() || $user->hasRole(\App\Enums\UserRole::ProductionSupervisor))) {
            $query->where('type', CreditNote::TYPE_SALES_RETURN)
                ->where('move_to', CreditNote::MOVE_TO_FACTORY);
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return CreditNoteForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CreditNoteInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CreditNotesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCreditNotes::route('/'),
            'create' => CreateCreditNote::route('/create'),
            'view' => ViewCreditNote::route('/{record}'),
            'edit' => EditCreditNote::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
