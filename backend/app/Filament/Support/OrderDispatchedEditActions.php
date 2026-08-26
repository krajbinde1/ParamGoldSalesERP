<?php

namespace App\Filament\Support;

use App\Actions\Orders\AdminRejectOrderEditPermission;
use App\Actions\Orders\ApplyDispatchedOrderTransportCorrection;
use App\Actions\Orders\ConfirmOrderEditPermission;
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
                ->modalHeading('Request Edit Permission')
                ->modalDescription('This dispatched order stays locked until the Director approves and Admin confirms. You may then correct Vehicle No., Transport Type, and Transport Charges once.')
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
                        ->body('The Director has been notified. This order remains locked until Director and Admin approval.')
                        ->success()
                        ->send();

                    if ($after) {
                        $after($order->fresh() ?? $order);
                    }
                }),
            Action::make('approveEditPermission')
                ->label('Approve Edit Permission')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (?Order $record = null): bool => ($order = $resolve($record)) !== null
                    && Gate::forUser(auth()->user())->allows('confirmDispatchedEdit', $order))
                ->requiresConfirmation()
                ->modalHeading('Approve Edit Permission')
                ->modalDescription('Director already approved this request. Confirming will unlock a one-time correction of Vehicle No., Transport Type, and Transport Charges. The order stays Dispatched.')
                ->modalSubmitActionLabel('Approve')
                ->action(function (?Order $record) use ($after, $resolve): void {
                    $order = $resolve($record);

                    try {
                        app(ConfirmOrderEditPermission::class)->execute(
                            order: $order,
                            actor: auth()->user(),
                        );
                    } catch (AuthorizationException|ValidationException $exception) {
                        $message = $exception instanceof ValidationException
                            ? (collect($exception->errors())->flatten()->first() ?: 'Unable to approve')
                            : $exception->getMessage();

                        Notification::make()
                            ->title($message)
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Edit permission approved')
                        ->body('You may now correct Vehicle No., Transport Type, and Transport Charges once. Saving will lock the order again.')
                        ->success()
                        ->send();

                    if ($after) {
                        $after($order->fresh() ?? $order);
                    }
                }),
            Action::make('rejectEditPermission')
                ->label('Reject Edit Permission')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (?Order $record = null): bool => ($order = $resolve($record)) !== null
                    && Gate::forUser(auth()->user())->allows('rejectDispatchedEditPermission', $order))
                ->modalHeading('Reject Edit Permission')
                ->modalSubmitActionLabel('Reject')
                ->form([
                    Textarea::make('rejection_remark')
                        ->label('Remark')
                        ->required()
                        ->minLength(3)
                        ->maxLength(2000)
                        ->rows(3),
                ])
                ->action(function (?Order $record, array $data) use ($after, $resolve): void {
                    $order = $resolve($record);

                    try {
                        app(AdminRejectOrderEditPermission::class)->execute(
                            order: $order,
                            actor: auth()->user(),
                            remark: $data['rejection_remark'],
                        );
                    } catch (AuthorizationException|ValidationException $exception) {
                        $message = $exception instanceof ValidationException
                            ? (collect($exception->errors())->flatten()->first() ?: 'Unable to reject')
                            : $exception->getMessage();

                        Notification::make()
                            ->title($message)
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Edit permission rejected')
                        ->body('The order stays locked. You may request Director approval again if needed.')
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
                ->modalHeading('Correct Transport Details')
                ->modalDescription('Admin-approved one-time correction. Saving will lock the order again. Status stays Dispatched.')
                ->modalSubmitActionLabel('Save Correction')
                ->fillForm(function (?Order $record = null) use ($resolve): array {
                    $order = $resolve($record);
                    if ($order === null) {
                        return [];
                    }

                    try {
                        return CorrectDispatchedTransportForm::fillFromOrder($order);
                    } catch (\Throwable) {
                        return [];
                    }
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
