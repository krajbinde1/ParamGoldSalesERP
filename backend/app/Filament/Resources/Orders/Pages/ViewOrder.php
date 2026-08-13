<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Actions\Orders\BillOrderWithDocument;
use App\Actions\Orders\RejectOrderWithRemarks;
use App\Actions\Orders\SendOrderForBilling;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\SendForBillForm;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Gate;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var Order $record */
        $record = $this->getRecord();

        return 'Order #'.$record->shortOrderNo();
    }

    public function getHeading(): string|Htmlable
    {
        /** @var Order $record */
        $record = $this->getRecord();

        return 'Order #'.$record->shortOrderNo();
    }

    public function getSubheading(): string|Htmlable|null
    {
        /** @var Order $record */
        $record = $this->getRecord();

        return $record->displayStatusLabel();
    }

    protected function getHeaderActions(): array
    {
        $record = $this->getRecord();

        return [
            Action::make('reject')
                ->label('Reject Order')
                ->color('danger')
                ->visible(fn (): bool => Gate::forUser(auth()->user())->allows('reject', $record))
                ->authorize(fn (): bool => Gate::forUser(auth()->user())->allows('reject', $record))
                ->modalHeading('Reject Order')
                ->form([
                    Textarea::make('rejection_reason')
                        ->label('Reason / Remarks')
                        ->required()
                        ->minLength(3)
                        ->rows(3),
                ])
                ->action(function (array $data) use ($record): void {
                    $user = auth()->user();
                    $role = $user?->isAdminUser()
                        ? Order::REJECTED_BY_ROLE_ADMIN
                        : Order::REJECTED_BY_ROLE_SALES_MANAGER;

                    app(RejectOrderWithRemarks::class)->execute(
                        order: $record,
                        actor: $user,
                        remark: $data['rejection_reason'],
                        rejectedByRole: $role,
                    );

                    $this->refreshFormData([
                        'status',
                        'rejected_by',
                        'rejected_by_role',
                        'rejected_at',
                        'rejection_remark',
                    ]);
                }),
            Action::make('sendForBill')
                ->label('Send for Bill')
                ->color('warning')
                ->visible(fn (): bool => Gate::forUser(auth()->user())->allows('sendForBill', $record))
                ->authorize(fn (): bool => Gate::forUser(auth()->user())->allows('sendForBill', $record))
                ->modalHeading('Send for Bill')
                ->modalSubmitActionLabel('Send for Bill')
                ->form(SendForBillForm::schema())
                ->action(function (array $data) use ($record): void {
                    $payload = SendForBillForm::resolvePayload($data);

                    app(SendOrderForBilling::class)->execute(
                        order: $record,
                        actor: auth()->user(),
                        vehicleNumber: $payload['vehicle']->vehicle_number,
                        transportFreight: $payload['transport_freight'],
                        transportRemark: $payload['transport_remark'],
                        vehicleId: $payload['vehicle']->id,
                    );

                    Notification::make()
                        ->title('Order sent for billing')
                        ->success()
                        ->send();

                    $this->refreshFormData([
                        'status',
                        'vehicle_id',
                        'vehicle_number',
                        'transport_amount',
                        'transport_remark',
                        'sent_for_bill_by',
                        'sent_for_bill_at',
                    ]);
                }),
            Action::make('viewBill')
                ->label('View / Download Bill')
                ->icon('heroicon-o-document-arrow-down')
                ->color('primary')
                ->url(fn (): ?string => $record->billUrl())
                ->openUrlInNewTab()
                ->visible(fn (): bool => (auth()->user()?->canActAsProductionSupervisor() ?? false)
                    && filled($record->bill_path)
                    && filled($record->billUrl())),
            Action::make('bill')
                ->label('Mark as Billed')
                ->color('warning')
                ->visible(fn (): bool => Gate::forUser(auth()->user())->allows('bill', $record))
                ->authorize(fn (): bool => Gate::forUser(auth()->user())->allows('bill', $record))
                ->form([
                    FileUpload::make('bill')
                        ->label('Upload Bill')
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(10240)
                        ->required()
                        ->storeFiles(false),
                    TextInput::make('bill_number')
                        ->label('Bill Number')
                        ->maxLength(100),
                    DatePicker::make('bill_date')
                        ->label('Bill Date')
                        ->default(now('Asia/Kolkata')->toDateString())
                        ->native(false),
                    Textarea::make('billing_remark')
                        ->label('Billing Remark')
                        ->rows(3),
                ])
                ->action(function (array $data) use ($record): void {
                    app(BillOrderWithDocument::class)->execute(
                        order: $record,
                        actor: auth()->user(),
                        bill: $data['bill'],
                        billNumber: $data['bill_number'] ?? null,
                        remark: $data['billing_remark'] ?? null,
                        billDate: $data['bill_date'] ?? null,
                    );

                    $this->refreshFormData([
                        'status',
                        'billed_at',
                        'billed_by',
                        'bill_path',
                        'bill_number',
                        'bill_date',
                        'billing_remark',
                    ]);
                }),
            Action::make('dispatch')
                ->label('Mark as Dispatched')
                ->color('info')
                ->modalHeading('Mark as Dispatched')
                ->modalSubmitActionLabel('Dispatched')
                ->visible(fn (): bool => $record->status === Order::STATUS_BILLED
                    && auth()->user()?->canActAsProductionSupervisor()
                    && Gate::forUser(auth()->user())->allows('dispatch', $record))
                ->authorize(fn (): bool => Gate::forUser(auth()->user())->allows('dispatch', $record))
                ->form([
                    Textarea::make('dispatch_remark')
                        ->label('Remark')
                        ->rows(3)
                        ->maxLength(2000),
                ])
                ->action(function (array $data) use ($record): void {
                    $record->dispatch(
                        userId: auth()->id(),
                        remark: $data['dispatch_remark'] ?? null,
                    );

                    Notification::make()
                        ->title('Order marked as dispatched')
                        ->success()
                        ->send();

                    $this->refreshFormData([
                        'status',
                        'dispatched_at',
                        'dispatched_by',
                        'dispatch_remark',
                    ]);
                }),
            EditAction::make()
                ->visible(fn (): bool => ! auth()->user()?->hasOrdersOnlyFilamentAccess()
                    && $record->canBeEdited()),
        ];
    }
}
