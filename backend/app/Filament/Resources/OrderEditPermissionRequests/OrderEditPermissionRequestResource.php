<?php

namespace App\Filament\Resources\OrderEditPermissionRequests;

use App\Filament\Resources\OrderEditPermissionRequests\Pages\ListOrderEditPermissionRequests;
use App\Filament\Resources\OrderEditPermissionRequests\Pages\ViewOrderEditPermissionRequest;
use App\Filament\Resources\OrderEditPermissionRequests\Schemas\OrderEditPermissionRequestInfolist;
use App\Filament\Resources\OrderEditPermissionRequests\Tables\OrderEditPermissionRequestsTable;
use App\Models\OrderEditPermissionRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OrderEditPermissionRequestResource extends Resource
{
    protected static ?string $model = OrderEditPermissionRequest::class;

    protected static ?string $slug = 'order-edit-requests';

    protected static string|\UnitEnum|null $navigationGroup = 'Sales Operations';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Order Edit Requests';

    protected static ?string $modelLabel = 'Order Edit Request';

    protected static ?string $pluralModelLabel = 'Order Edit Requests';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockOpen;

    protected static ?string $recordTitleAttribute = 'id';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->isDirectorUser() ?? false) && ! ($user?->isAdminUser() ?? false);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) {
            return null;
        }

        $count = OrderEditPermissionRequest::query()->pending()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function infolist(Schema $schema): Schema
    {
        return OrderEditPermissionRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrderEditPermissionRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrderEditPermissionRequests::route('/'),
            'view' => ViewOrderEditPermissionRequest::route('/{record}'),
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
            ->with([
                'order:id,order_no,status,dealer_id,vehicle_number,transport_charge_type,transport_amount,grand_total',
                'order.dealer:id,firm_name,village',
                'requestedByUser:id,name',
                'reviewedByUser:id,name',
                'adminReviewedByUser:id,name',
                'editedByUser:id,name',
            ])
            ->latest('id');
    }
}
