<?php

namespace App\Filament\Resources\ProductionBatches;

use App\Filament\Concerns\InventoryFilamentAccess;
use App\Filament\Resources\ProductionBatches\Pages\CreateProductionEntry;
use App\Filament\Resources\ProductionBatches\Pages\ListProductionBatches;
use App\Filament\Resources\ProductionBatches\Pages\ViewProductionBatch;
use App\Filament\Resources\ProductionBatches\Schemas\ProductionBatchInfolist;
use App\Filament\Resources\ProductionBatches\Tables\ProductionBatchesTable;
use App\Models\ProductionBatch;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ProductionBatchResource extends Resource
{
    use InventoryFilamentAccess;

    protected static ?string $model = ProductionBatch::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory & Manufacturing';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Production Batches';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $recordTitleAttribute = 'batch_number';

    public static function infolist(Schema $schema): Schema
    {
        return ProductionBatchInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductionBatchesTable::configure($table);
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
            'index' => ListProductionBatches::route('/'),
            'production-entry' => CreateProductionEntry::route('/production-entry'),
            'view' => ViewProductionBatch::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'product:id,product_code,product_name',
            'semiFinished:id,material_code,material_name',
            'supervisor:id,name',
        ]);
    }

    /**
     * Production batches are only ever created through the guided
     * "New Production Entry" workflow, never through a plain create form.
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
        if (! $record->isEditable()) {
            return false;
        }

        $user = auth()->user();

        return $user !== null && ($user->usesAdminDirectorDashboard() || $user->isAdminUser());
    }

    /**
     * Users allowed to use the production entry workflow: production supervisors,
     * directors, and admins.
     */
    public static function canPostProduction(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return $user->canActAsProductionSupervisor()
            || $user->usesAdminDirectorDashboard()
            || $user->isAdminUser();
    }
}
