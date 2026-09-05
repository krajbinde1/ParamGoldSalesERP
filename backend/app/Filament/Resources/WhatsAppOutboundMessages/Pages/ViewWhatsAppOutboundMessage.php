<?php

namespace App\Filament\Resources\WhatsAppOutboundMessages\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\WhatsAppOutboundMessages\WhatsAppOutboundMessageResource;
use App\Models\WhatsAppOutboundMessage;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewWhatsAppOutboundMessage extends ViewRecord
{
    protected static string $resource = WhatsAppOutboundMessageResource::class;

    protected function getHeaderActions(): array
    {
        /** @var WhatsAppOutboundMessage $record */
        $record = $this->getRecord();

        return [
            Action::make('openOrder')
                ->label('Open Order')
                ->url(fn (): ?string => $record->source_type === WhatsAppOutboundMessage::SOURCE_BILL
                    ? OrderResource::getUrl('view', ['record' => $record->source_id])
                    : null)
                ->visible(fn (): bool => $record->source_type === WhatsAppOutboundMessage::SOURCE_BILL),
        ];
    }
}
