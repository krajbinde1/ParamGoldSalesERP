<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Actions\Orders\BillOrderWithDocument;
use App\Actions\Orders\DispatchOrderWithTransport;
use App\Actions\Orders\RejectOrderWithRemarks;
use App\Actions\Orders\SendOrderForBilling;
use App\Enums\TransportType;
use App\Filament\Support\SendForBillForm;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        $ordersOnlyUser = fn (): bool => auth()->user()?->hasOrdersOnlyFilamentAccess() ?? false;
        $isProductionSupervisor = fn (): bool => auth()->user()?->canActAsProductionSupervisor() ?? false;

        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('order_no')
                    ->label('Order No')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(function (string $state, Order $record) use ($isProductionSupervisor): string {
                        return $isProductionSupervisor() ? $record->shortOrderNo() : $state;
                    }),
                TextColumn::make('order_date')->date()->sortable(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->visible(fn () => ! $isProductionSupervisor()),
                TextColumn::make('dealer.firm_name')->label('Dealer')->searchable()->sortable(),
                TextColumn::make('salesEmployee.full_name')->label('Sales Employee')->placeholder('-')->searchable(),
                TextColumn::make('payment_type')->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state, Order $record): string => $record->displayStatusLabel())
                    ->color(fn (string $state): string => Order::statusColor($state))
                    ->sortable(),
                TextColumn::make('grand_total')
                    ->label('Grand Total')
                    ->money('INR')
                    ->sortable()
                    ->visible(fn () => ! $isProductionSupervisor()),
            ])
            ->filters([
                SelectFilter::make('payment_type')->options(['Cash' => 'Cash', 'Credit' => 'Credit']),
                SelectFilter::make('status')->options(Order::statusLabels()),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (Order $record): bool => ! $ordersOnlyUser() && $record->canBeEdited()),
                Action::make('submitForApproval')
                    ->label('Submit for Approval')
                    ->color('warning')
                    ->visible(fn (Order $record): bool => ! $ordersOnlyUser() && $record->canTransitionTo('pending_approval'))
                    ->action(fn (Order $record) => $record->transitionTo('pending_approval')),
                Action::make('approve')
                    ->label('Approve')
                    ->color('success')
                    ->visible(fn (Order $record): bool => Gate::forUser(auth()->user())->allows('approve', $record))
                    ->authorize(fn (Order $record): bool => Gate::forUser(auth()->user())->allows('approve', $record))
                    ->requiresConfirmation()
                    ->action(fn (Order $record) => $record->approve(auth()->id())),
                Action::make('reject')
                    ->label('Reject Order')
                    ->color('danger')
                    ->visible(fn (Order $record): bool => Gate::forUser(auth()->user())->allows('reject', $record))
                    ->authorize(fn (Order $record): bool => Gate::forUser(auth()->user())->allows('reject', $record))
                    ->modalHeading('Reject Order')
                    ->form([
                        Textarea::make('rejection_reason')
                            ->label('Reason / Remarks')
                            ->required()
                            ->minLength(3)
                            ->rows(3),
                    ])
                    ->action(function (Order $record, array $data): void {
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
                    }),
                Action::make('sendForBill')
                    ->label('Send for Bill')
                    ->color('warning')
                    ->visible(fn (Order $record): bool => Gate::forUser(auth()->user())->allows('sendForBill', $record))
                    ->authorize(fn (Order $record): bool => Gate::forUser(auth()->user())->allows('sendForBill', $record))
                    ->modalHeading('Send for Bill')
                    ->modalSubmitActionLabel('Send for Bill')
                    ->form(SendForBillForm::schema())
                    ->action(function (Order $record, array $data): void {
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
                    }),
                Action::make('bill')
                    ->label('Mark as Billed')
                    ->color('warning')
                    ->visible(fn (Order $record): bool => Gate::forUser(auth()->user())->allows('bill', $record))
                    ->authorize(fn (Order $record): bool => Gate::forUser(auth()->user())->allows('bill', $record))
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
                    ->action(function (Order $record, array $data): void {
                        app(BillOrderWithDocument::class)->execute(
                            order: $record,
                            actor: auth()->user(),
                            bill: $data['bill'],
                            billNumber: $data['bill_number'] ?? null,
                            remark: $data['billing_remark'] ?? null,
                            billDate: $data['bill_date'] ?? null,
                        );
                    }),
                Action::make('dispatch')
                    ->label('Mark as Dispatched')
                    ->color('info')
                    ->visible(fn (Order $record): bool => $record->status === Order::STATUS_BILLED
                        && auth()->user()?->canActAsProductionSupervisor()
                        && Gate::forUser(auth()->user())->allows('dispatch', $record))
                    ->authorize(fn (Order $record): bool => Gate::forUser(auth()->user())->allows('dispatch', $record))
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
                        Textarea::make('dispatch_remark')
                            ->label('Dispatch Remark')
                            ->rows(3),
                    ])
                    ->action(function (Order $record, array $data): void {
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
                        );
                    }),
                Action::make('cancel')
                    ->label('Cancel')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Order $record): bool => ! $ordersOnlyUser()
                        && ! auth()->user()?->isAdminUser()
                        && ! auth()->user()?->isDirectorUser()
                        && $record->canTransitionTo('cancelled'))
                    ->action(fn (Order $record) => $record->transitionTo('cancelled')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->visible(fn (): bool => ! $ordersOnlyUser()
                        && ! (auth()->user()?->isAdminUser() ?? false)
                        && ! (auth()->user()?->isDirectorUser() ?? false)),
                    ForceDeleteBulkAction::make()->visible(fn (): bool => ! $ordersOnlyUser()
                        && ! (auth()->user()?->isAdminUser() ?? false)
                        && ! (auth()->user()?->isDirectorUser() ?? false)),
                    RestoreBulkAction::make()->visible(fn (): bool => ! $ordersOnlyUser()),
                ]),
            ]);
    }
}
