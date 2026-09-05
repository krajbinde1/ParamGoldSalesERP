<?php

namespace App\Filament\Support;

use App\Models\Order;
use App\Services\WhatsApp\WhatsAppOutboundEnqueueService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

final class ResendBillWhatsAppAction
{
    public static function make(): Action
    {
        return Action::make('resendBillWhatsApp')
            ->label('Resend Bill on WhatsApp')
            ->icon('heroicon-o-chat-bubble-left-right')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Resend Sales Bill on WhatsApp?')
            ->modalDescription('This sends the currently uploaded Sales Bill PDF to the dealer WhatsApp number. It does not change the billed order.')
            ->visible(fn (Order $record): bool => auth()->user()?->can('resendWhatsAppBill', $record) ?? false)
            ->authorize(fn (Order $record): bool => auth()->user()?->can('resendWhatsAppBill', $record) ?? false)
            ->action(function (Order $record): void {
                $fresh = $record->fresh() ?? $record;
                $message = app(WhatsAppOutboundEnqueueService::class)->resendBilledOrder($fresh);
                $record->unsetRelation('latestBillWhatsAppMessage');
                $record->unsetRelation('whatsAppBillMessages');
                $record->refresh();

                if ($message->isFailed()) {
                    Notification::make()
                        ->title('WhatsApp resend was logged as Failed')
                        ->body($message->error ?: 'The dealer number or bill file could not be used.')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Sales Bill queued for WhatsApp')
                    ->success()
                    ->send();
            });
    }
}
