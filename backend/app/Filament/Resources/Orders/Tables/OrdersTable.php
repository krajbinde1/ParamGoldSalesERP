<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Actions\Orders\BillOrderWithDocument;
use App\Actions\Orders\DispatchOrder;
use App\Actions\Orders\RejectOrderWithRemarks;
use App\Actions\Orders\SendOrderForBilling;
use App\Filament\Support\SendForBillForm;
use App\Filament\Support\TodayDateFilter;
use App\Models\Order;
use App\Support\AttendanceCalendar;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        $ordersOnlyUser = fn (): bool => auth()->user()?->hasOrdersOnlyFilamentAccess() ?? false;
        $isProductionSupervisor = auth()->user()?->canActAsProductionSupervisor() ?? false;

        $filters = [
            SelectFilter::make('payment_type')->options(['Cash' => 'Cash', 'Credit' => 'Credit']),
            TodayDateFilter::make('order_date', 'Order Date'),
            Filter::make('action_required')
                ->label('Action required')
                ->toggle()
                ->query(fn (Builder $query): Builder => $query->whereIn('status', [
                    Order::STATUS_PENDING_APPROVAL,
                    Order::STATUS_PENDING_FOR_BILLING,
                    Order::STATUS_BILLED,
                ])),
            SelectFilter::make('stuck_since')
                ->label('Delayed')
                ->options([
                    'pending_24h' => 'Pending approval > 24 hours',
                    'billing_12h' => 'Waiting for billing > 12 hours',
                    'dispatch_24h' => 'Billed not dispatched > 24 hours',
                ])
                ->query(function (Builder $query, array $data): Builder {
                    $now = AttendanceCalendar::now();

                    return match ($data['value'] ?? null) {
                        'pending_24h' => $query
                            ->where('status', Order::STATUS_PENDING_APPROVAL)
                            ->where('created_at', '<=', $now->copy()->subHours(24)),
                        'billing_12h' => $query
                            ->where('status', Order::STATUS_PENDING_FOR_BILLING)
                            ->whereNotNull('sent_for_bill_at')
                            ->where('sent_for_bill_at', '<=', $now->copy()->subHours(12)),
                        'dispatch_24h' => $query
                            ->where('status', Order::STATUS_BILLED)
                            ->whereNotNull('billed_at')
                            ->where('billed_at', '<=', $now->copy()->subHours(24)),
                        default => $query,
                    };
                }),
        ];

        // Do not register a status SelectFilter for PS at all.
        // A hidden-but-registered filter still applies leftover filters[status]
        // state and empties tabs (e.g. tab=billed AND status=approved).
        if (! $isProductionSupervisor) {
            $filters[] = SelectFilter::make('status')->options(Order::statusLabels());
            $filters[] = TrashedFilter::make();
        }

        return $table
            ->defaultSort('created_at', 'desc')
            ->recordActionsColumnLabel('Action')
            ->columns([
                TextColumn::make('order_no')
                    ->label('Order No')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (string $state, Order $record): string => $record->shortOrderNo()),
                TextColumn::make('order_date')
                    ->date()
                    ->sortable()
                    ->visible(fn () => $isProductionSupervisor),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->visible(fn () => ! $isProductionSupervisor),
                TextColumn::make('dealer.firm_name')->label('Dealer')->searchable()->sortable(),
                TextColumn::make('salesEmployee.full_name')->label('Sales Employee')->placeholder('-')->searchable(),
                TextColumn::make('payment_type')
                    ->badge()
                    ->visible(fn () => $isProductionSupervisor),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state, Order $record): string => $record->displayStatusLabel())
                    ->color(fn (string $state): string => Order::statusColor($state))
                    ->sortable(),
                TextColumn::make('bill_number')
                    ->label('Bill No')
                    ->placeholder('-')
                    ->toggleable()
                    ->visible(fn () => $isProductionSupervisor),
                TextColumn::make('bill_date')
                    ->label('Bill Date')
                    ->date()
                    ->placeholder('-')
                    ->toggleable()
                    ->visible(fn () => $isProductionSupervisor),
                TextColumn::make('grand_total')
                    ->label('Final Grand Total')
                    ->money('INR')
                    ->sortable()
                    ->visible(fn () => ! $isProductionSupervisor),
            ])
            ->filters($filters)
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
                    ->form(fn (Order $record): array => SendForBillForm::schema($record))
                    ->action(function (Order $record, array $data): void {
                        $payload = SendForBillForm::resolvePayload($data);

                        app(SendOrderForBilling::class)->execute(
                            order: $record,
                            actor: auth()->user(),
                            vehicleNumber: $payload['vehicle']->vehicle_number,
                            transportFreight: $payload['transport_freight'],
                            transportRemark: $payload['transport_remark'],
                            vehicleId: $payload['vehicle']->id,
                            transportChargeType: $payload['transport_charge_type'],
                        );

                        Notification::make()
                            ->title('Order sent for billing')
                            ->success()
                            ->send();
                    }),
                Action::make('viewBill')
                    ->label('View Bill')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('primary')
                    ->url(fn (Order $record): ?string => $record->billUrl())
                    ->openUrlInNewTab()
                    ->visible(fn (Order $record): bool => $isProductionSupervisor
                        && filled($record->bill_path)
                        && filled($record->billUrl())),
                Action::make('bill')
                    ->label('Mark as Billed')
                    ->color('warning')
                    ->visible(fn (Order $record): bool => Gate::forUser(auth()->user())->allows('bill', $record))
                    ->authorize(fn (Order $record): bool => Gate::forUser(auth()->user())->allows('bill', $record))
                    ->form(fn (Order $record): array => [
                        Placeholder::make('billing_transport_summary')
                            ->hiddenLabel()
                            ->content(fn (): HtmlString => new HtmlString(
                                view('filament.resources.orders.partials.billing-transport-summary', [
                                    'record' => $record,
                                ])->render()
                            )),
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
                    ->icon('heroicon-o-truck')
                    ->color('success')
                    ->modalHeading('Mark as Dispatched')
                    ->modalSubmitActionLabel('Confirm Dispatch')
                    ->modalCancelActionLabel('Cancel')
                    ->visible(fn (Order $record): bool => $record->status === Order::STATUS_BILLED
                        && auth()->user()?->canActAsProductionSupervisor()
                        && Gate::forUser(auth()->user())->allows('dispatch', $record))
                    ->authorize(fn (Order $record): bool => Gate::forUser(auth()->user())->allows('dispatch', $record))
                    ->form([
                        Textarea::make('dispatch_remark')
                            ->label('Remark')
                            ->placeholder('Optional')
                            ->rows(3)
                            ->maxLength(2000),
                    ])
                    ->action(function (Order $record, array $data): void {
                        app(DispatchOrder::class)->execute(
                            order: $record,
                            actor: auth()->user(),
                            remark: $data['dispatch_remark'] ?? null,
                        );

                        Notification::make()
                            ->title('Order marked as dispatched')
                            ->success()
                            ->send();
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
