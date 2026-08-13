<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Actions\Orders\BillOrderWithDocument;
use App\Actions\Orders\DispatchOrderWithTransport;
use App\Actions\Orders\RejectOrderWithRemarks;
use App\Enums\TransportType;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

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
                ->visible(fn (): bool => $record->status === Order::STATUS_BILLED
                    && auth()->user()?->canActAsProductionSupervisor()
                    && Gate::forUser(auth()->user())->allows('dispatch', $record))
                ->authorize(fn (): bool => Gate::forUser(auth()->user())->allows('dispatch', $record))
                ->form([
                    DatePicker::make('dispatch_date')
                        ->label('Dispatch Date')
                        ->default(now('Asia/Kolkata')->toDateString())
                        ->native(false),
                    Select::make('transport_type')
                        ->label('Transport Type')
                        ->options(TransportType::options())
                        ->required(),
                    TextInput::make('transport_amount')
                        ->label('Transport Amount')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('₹')
                        ->required(),
                    TextInput::make('transporter_name')
                        ->label('Transport Name')
                        ->maxLength(255),
                    TextInput::make('vehicle_number')
                        ->label('Vehicle Number')
                        ->maxLength(50),
                    TextInput::make('lr_number')
                        ->label('LR Number')
                        ->maxLength(100),
                    FileUpload::make('lr_document')
                        ->label('LR / Dispatch Document')
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(10240)
                        ->storeFiles(false),
                    Textarea::make('dispatch_remark')
                        ->label('Dispatch Remark')
                        ->rows(3),
                ])
                ->action(function (array $data) use ($record): void {
                    app(DispatchOrderWithTransport::class)->execute(
                        order: $record,
                        actor: auth()->user(),
                        transportType: $data['transport_type'],
                        transportAmount: (float) $data['transport_amount'],
                        remark: $data['dispatch_remark'] ?? null,
                        dispatchDate: $data['dispatch_date'] ?? null,
                        transporterName: $data['transporter_name'] ?? null,
                        vehicleNumber: $data['vehicle_number'] ?? null,
                        lrNumber: $data['lr_number'] ?? null,
                        lrDocument: $data['lr_document'] ?? null,
                    );

                    $this->refreshFormData([
                        'status',
                        'transport_type',
                        'transport_amount',
                        'subtotal_before_transport',
                        'taxable_amount_after_transport',
                        'gst_amount',
                        'grand_total',
                        'dispatched_at',
                        'dispatch_date',
                        'dispatched_by',
                        'dispatch_remark',
                        'transporter_name',
                        'vehicle_number',
                        'lr_number',
                        'lr_document_path',
                    ]);
                }),
            EditAction::make()
                ->visible(fn (): bool => ! auth()->user()?->hasOrdersOnlyFilamentAccess()
                    && $record->canBeEdited()),
        ];
    }
}
