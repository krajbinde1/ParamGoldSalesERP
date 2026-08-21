<?php

namespace App\Filament\Support;

use App\Actions\Orders\HoldOrder;
use App\Actions\Orders\ReleaseOrderHold;
use App\Actions\Orders\RevertOrderToManager;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;

final class OrderHoldRevertActions
{
    /**
     * @return list<Action>
     */
    public static function make(?callable $after = null, ?Order $pageRecord = null): array
    {
        $resolve = function (?Order $injected = null) use ($pageRecord): ?Order {
            return $injected ?? $pageRecord;
        };

        return [
            Action::make('holdOrder')
                ->label('Hold Order')
                ->color('warning')
                ->visible(fn (?Order $record = null): bool => ($order = $resolve($record)) !== null
                    && Gate::forUser(auth()->user())->allows('hold', $order))
                ->authorize(fn (?Order $record = null): bool => ($order = $resolve($record)) !== null
                    && Gate::forUser(auth()->user())->allows('hold', $order))
                ->modalHeading('Hold Order')
                ->modalDescription('The manager approval stays valid. This order cannot be sent for billing until the hold is released.')
                ->modalSubmitActionLabel('Confirm Hold')
                ->form([
                    Textarea::make('hold_remark')
                        ->label('Hold Remark / Reason')
                        ->placeholder("Stock not available\nProduction issue\nQuantity clarification required\nTransport issue\nOther operational reason")
                        ->required()
                        ->minLength(3)
                        ->rows(3),
                ])
                ->action(function (?Order $record, array $data) use ($after, $resolve): void {
                    $order = $resolve($record);
                    app(HoldOrder::class)->execute(
                        order: $order,
                        actor: auth()->user(),
                        remark: $data['hold_remark'],
                    );

                    Notification::make()
                        ->title('Order put on hold')
                        ->success()
                        ->send();

                    if ($after) {
                        $after($order);
                    }
                }),
            Action::make('releaseHold')
                ->label('Release Hold')
                ->color('success')
                ->visible(fn (?Order $record = null): bool => ($order = $resolve($record)) !== null
                    && Gate::forUser(auth()->user())->allows('releaseHold', $order))
                ->authorize(fn (?Order $record = null): bool => ($order = $resolve($record)) !== null
                    && Gate::forUser(auth()->user())->allows('releaseHold', $order))
                ->requiresConfirmation()
                ->modalHeading('Release Hold')
                ->modalDescription('The order will return to Production Supervisor for Send for Bill. Manager approval remains valid.')
                ->action(function (?Order $record = null) use ($after, $resolve): void {
                    $order = $resolve($record);
                    app(ReleaseOrderHold::class)->execute(
                        order: $order,
                        actor: auth()->user(),
                    );

                    Notification::make()
                        ->title('Hold released')
                        ->success()
                        ->send();

                    if ($after) {
                        $after($order);
                    }
                }),
            Action::make('revertToManager')
                ->label('Revert to Manager')
                ->color('gray')
                ->visible(fn (?Order $record = null): bool => ($order = $resolve($record)) !== null
                    && Gate::forUser(auth()->user())->allows('revertToManager', $order))
                ->authorize(fn (?Order $record = null): bool => ($order = $resolve($record)) !== null
                    && Gate::forUser(auth()->user())->allows('revertToManager', $order))
                ->modalHeading('Revert to Manager')
                ->modalDescription('This is not a rejection. The manager must review and re-approve before production can continue.')
                ->modalSubmitActionLabel('Confirm Revert')
                ->form([
                    Textarea::make('revert_remark')
                        ->label('Revert Remark')
                        ->placeholder('Please correct product quantity before production.')
                        ->required()
                        ->minLength(3)
                        ->rows(3),
                ])
                ->action(function (?Order $record, array $data) use ($after, $resolve): void {
                    $order = $resolve($record);
                    app(RevertOrderToManager::class)->execute(
                        order: $order,
                        actor: auth()->user(),
                        remark: $data['revert_remark'],
                    );

                    Notification::make()
                        ->title('Order returned to manager')
                        ->success()
                        ->send();

                    if ($after) {
                        $after($order);
                    }
                }),
        ];
    }
}
