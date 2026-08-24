<?php

namespace App\Filament\Resources\OrderEditPermissionRequests\Pages;

use App\Actions\Orders\ApproveOrderEditPermission;
use App\Actions\Orders\RejectOrderEditPermission;
use App\Filament\Resources\OrderEditPermissionRequests\OrderEditPermissionRequestResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\OrderEditPermissionRequest;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ViewOrderEditPermissionRequest extends ViewRecord
{
    protected static string $resource = OrderEditPermissionRequestResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var OrderEditPermissionRequest $record */
        $record = $this->getRecord();
        $orderNo = $record->order?->shortOrderNo() ?: '#'.$record->order_id;

        return 'Edit Request · Order '.$orderNo;
    }

    protected function getHeaderActions(): array
    {
        /** @var OrderEditPermissionRequest $record */
        $record = $this->getRecord();

        return [
            Action::make('viewOrder')
                ->label('View Order')
                ->color('gray')
                ->url(fn (): ?string => $record->order
                    ? OrderResource::getUrl('view', ['record' => $record->order])
                    : null)
                ->openUrlInNewTab()
                ->visible(fn (): bool => $record->order !== null),
            Action::make('approve')
                ->label('Approve')
                ->color('success')
                ->visible(fn (): bool => Gate::forUser(auth()->user())->allows('approve', $record))
                ->authorize(fn (): bool => Gate::forUser(auth()->user())->allows('approve', $record))
                ->requiresConfirmation()
                ->modalHeading('Approve Edit Permission')
                ->modalDescription('Admin will be allowed a one-time correction of Vehicle No., Transport Type, and Transport Charges. The order stays Dispatched.')
                ->modalSubmitActionLabel('Approve')
                ->action(function () use ($record): void {
                    try {
                        app(ApproveOrderEditPermission::class)->execute(
                            request: $record,
                            actor: auth()->user(),
                        );
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title(collect($exception->errors())->flatten()->first() ?: 'Unable to approve')
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Edit permission approved')
                        ->success()
                        ->send();

                    $this->record->refresh();
                }),
            Action::make('reject')
                ->label('Reject')
                ->color('danger')
                ->visible(fn (): bool => Gate::forUser(auth()->user())->allows('reject', $record))
                ->authorize(fn (): bool => Gate::forUser(auth()->user())->allows('reject', $record))
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
                ->action(function (array $data) use ($record): void {
                    try {
                        app(RejectOrderEditPermission::class)->execute(
                            request: $record,
                            actor: auth()->user(),
                            remark: $data['rejection_remark'],
                        );
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title(collect($exception->errors())->flatten()->first() ?: 'Unable to reject')
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Edit permission rejected')
                        ->success()
                        ->send();

                    $this->record->refresh();
                }),
        ];
    }
}
