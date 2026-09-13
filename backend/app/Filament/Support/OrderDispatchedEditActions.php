<?php

namespace App\Filament\Support;

use App\Actions\Orders\ApplyDispatchedOrderTransportCorrection;
use App\Actions\Orders\RequestOrderEditPermission;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class OrderDispatchedEditActions
{
    /**
     * @return list<Action>
     */
    public static function make(?callable $after = null, ?Order $pageRecord = null): array
    {
        $resolve = function (?Order $injected = null) use ($pageRecord): ?Order {
            return $injected ?? $pageRecord;
        };

        $bindRecord = function (Action $action) use ($pageRecord): Action {
            if ($pageRecord !== null) {
                $action->record($pageRecord);
            }

            return $action;
        };

        return [
            $bindRecord(Action::make('requestEditPermission')
                ->label('Request Edit Permission')
                ->icon('heroicon-o-lock-open')
                ->color('warning')
                ->visible(fn (?Order $record = null): bool => ($order = $resolve($record)) !== null
                    && Gate::forUser(auth()->user())->allows('requestDispatchedEdit', $order))
                ->modalHeading('Request Edit Permission')
                ->modalDescription('This dispatched order stays locked until the Director approves. You may then correct the full bill once: products, cases/quantity, rate, discount %, vehicle, transport type, and transport charges.')
                ->modalSubmitActionLabel('Send Request to Director')
                ->form([
                    Textarea::make('reason')
                        ->label('Reason for Edit')
                        ->placeholder('Incorrect quantity / rate / discount / vehicle / transport vs the Tally bill.')
                        ->required()
                        ->minLength(3)
                        ->maxLength(2000)
                        ->rows(4),
                ])
                ->action(function (?Order $record, array $data) use ($after, $resolve): void {
                    $order = $resolve($record);

                    try {
                        app(RequestOrderEditPermission::class)->execute(
                            order: $order,
                            actor: auth()->user(),
                            reason: $data['reason'],
                        );
                    } catch (AuthorizationException|ValidationException $exception) {
                        $message = $exception instanceof ValidationException
                            ? (collect($exception->errors())->flatten()->first() ?: 'Unable to send request')
                            : $exception->getMessage();

                        Notification::make()
                            ->title($message)
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Edit permission requested')
                        ->body('The Director has been notified. This order remains locked until approval.')
                        ->success()
                        ->send();

                    if ($after) {
                        $after($order->fresh() ?? $order);
                    }
                })),
            $bindRecord(Action::make('correctDispatchedTransport')
                ->label('Correct Bill Details')
                ->icon('heroicon-o-pencil-square')
                ->color('primary')
                ->visible(fn (?Order $record = null): bool => ($order = $resolve($record)) !== null
                    && Gate::forUser(auth()->user())->allows('correctDispatchedTransport', $order))
                ->modalHeading('Correct Bill Details')
                ->modalDescription('Director-approved one-time correction to match the existing Tally bill. Saving recalculates totals, updates the dealer Debit, adjusts finished stock only for the quantity difference, and locks the order again. Status stays Dispatched. Tally is not changed and a duplicate Tally Sales voucher is not created.')
                ->modalSubmitActionLabel('Save Correction')
                ->fillForm(function (?Order $record = null) use ($resolve): array {
                    $order = $resolve($record);

                    return $order !== null
                        ? CorrectDispatchedTransportForm::fillFromOrder($order)
                        : [];
                })
                ->form(function (?Order $record = null) use ($resolve): array {
                    $order = $resolve($record);

                    return $order !== null
                        ? CorrectDispatchedTransportForm::schema($order)
                        : [];
                })
                ->action(function (?Order $record, array $data) use ($after, $resolve): void {
                    $order = $resolve($record);
                    if ($order === null) {
                        Notification::make()
                            ->title('Unable to load this dispatched order for correction.')
                            ->danger()
                            ->send();

                        return;
                    }
                    try {
                        app(ApplyDispatchedOrderTransportCorrection::class)->execute(
                            order: $order,
                            actor: auth()->user(),
                            vehicleId: (int) ($data['vehicle_id'] ?? 0),
                            transportChargeType: (string) ($data['transport_charge_type'] ?? ''),
                            transportFreight: is_numeric($data['transport_freight'] ?? null)
                                ? (float) $data['transport_freight']
                                : -1,
                            items: isset($data['items']) && is_array($data['items'])
                                ? array_values($data['items'])
                                : null,
                        );
                    } catch (AuthorizationException|ValidationException $exception) {
                        $message = $exception instanceof ValidationException
                            ? (collect($exception->errors())->flatten()->first() ?: 'Unable to save correction')
                            : $exception->getMessage();

                        Notification::make()
                            ->title($message)
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Order details corrected')
                        ->body('The order is locked again. Further corrections need a new Director approval.')
                        ->success()
                        ->send();

                    if ($after) {
                        $after($order->fresh() ?? $order);
                    }
                })),
        ];
    }
}
