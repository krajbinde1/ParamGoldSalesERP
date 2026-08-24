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

        return [
            Action::make('requestEditPermission')
                ->label('Request Edit Permission')
                ->icon('heroicon-o-lock-open')
                ->color('warning')
                ->visible(fn (?Order $record = null): bool => ($order = $resolve($record)) !== null
                    && Gate::forUser(auth()->user())->allows('requestDispatchedEdit', $order))
                ->authorize(fn (?Order $record = null): bool => ($order = $resolve($record)) !== null
                    && Gate::forUser(auth()->user())->allows('requestDispatchedEdit', $order))
                ->modalHeading('Request Edit Permission')
                ->modalDescription('This dispatched order stays locked until the Director approves. You may then correct Vehicle No., Transport Type, and Transport Charges once.')
                ->modalSubmitActionLabel('Send Request to Director')
                ->form([
                    Textarea::make('reason')
                        ->label('Reason for Edit')
                        ->placeholder('Incorrect vehicle number / transport type / transport charges entered at Send for Bill.')
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
                }),
            Action::make('correctDispatchedTransport')
                ->label('Correct Transport Details')
                ->icon('heroicon-o-pencil-square')
                ->color('primary')
                ->visible(fn (?Order $record = null): bool => ($order = $resolve($record)) !== null
                    && Gate::forUser(auth()->user())->allows('correctDispatchedTransport', $order))
                ->authorize(fn (?Order $record = null): bool => ($order = $resolve($record)) !== null
                    && Gate::forUser(auth()->user())->allows('correctDispatchedTransport', $order))
                ->modalHeading('Correct Transport Details')
                ->modalDescription('Director-approved one-time correction. Saving will lock the order again. Status stays Dispatched.')
                ->modalSubmitActionLabel('Save Correction')
                ->fillForm(function (?Order $record = null) use ($resolve): array {
                    $order = $resolve($record);

                    return $order ? CorrectDispatchedTransportForm::fillFromOrder($order) : [];
                })
                ->form(fn (?Order $record = null): array => ($order = $resolve($record)) !== null
                    ? CorrectDispatchedTransportForm::schema($order)
                    : [])
                ->action(function (?Order $record, array $data) use ($after, $resolve): void {
                    $order = $resolve($record);
                    try {
                        app(ApplyDispatchedOrderTransportCorrection::class)->execute(
                            order: $order,
                            actor: auth()->user(),
                            vehicleId: (int) ($data['vehicle_id'] ?? 0),
                            transportChargeType: (string) ($data['transport_charge_type'] ?? ''),
                            transportFreight: is_numeric($data['transport_freight'] ?? null)
                                ? (float) $data['transport_freight']
                                : -1,
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
                }),
        ];
    }
}
