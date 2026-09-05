<?php

namespace App\Filament\Resources\WhatsAppOutboundMessages;

use App\Filament\Resources\WhatsAppOutboundMessages\Pages\ListWhatsAppOutboundMessages;
use App\Filament\Resources\WhatsAppOutboundMessages\Pages\ViewWhatsAppOutboundMessage;
use App\Filament\Resources\WhatsAppOutboundMessages\Schemas\WhatsAppOutboundMessageInfolist;
use App\Filament\Resources\WhatsAppOutboundMessages\Tables\WhatsAppOutboundMessagesTable;
use App\Models\WhatsAppOutboundMessage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class WhatsAppOutboundMessageResource extends Resource
{
    protected static ?string $model = WhatsAppOutboundMessage::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Sales Operations';

    protected static ?int $navigationSort = 12;

    protected static ?string $navigationLabel = 'WhatsApp Log';

    protected static ?string $modelLabel = 'WhatsApp Message';

    protected static ?string $pluralModelLabel = 'WhatsApp Log';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $recordTitleAttribute = 'erp_reference';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->isAdminUser() ?? false) || ($user?->isDirectorUser() ?? false);
    }

    public static function infolist(Schema $schema): Schema
    {
        return WhatsAppOutboundMessageInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WhatsAppOutboundMessagesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWhatsAppOutboundMessages::route('/'),
            'view' => ViewWhatsAppOutboundMessage::route('/{record}'),
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
