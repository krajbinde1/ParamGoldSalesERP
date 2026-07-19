<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Actions\Orders\DispatchOrderWithTransport;
use App\Enums\TransportType;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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

        return $table
            ->columns([
                TextColumn::make('order_no')->searchable()->sortable(),
                TextColumn::make('order_date')->date()->sortable(),
                TextColumn::make('dealer.firm_name')->label('Dealer')->searchable()->sortable(),
                TextColumn::make('salesEmployee.full_name')->label('Sales Employee')->placeholder('-')->searchable(),
                TextColumn::make('payment_type')->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Order::statusLabels()[$state] ?? $state)
                    ->color(fn (string $state): string => Order::statusColor($state))
                    ->sortable(),
                TextColumn::make('grand_total')->label('Grand Total')->money('INR')->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
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
                    ->label('Reject')
                    ->color('danger')
                    ->visible(fn (Order $record): bool => Gate::forUser(auth()->user())->allows('reject', $record))
                    ->authorize(fn (Order $record): bool => Gate::forUser(auth()->user())->allows('reject', $record))
                    ->form([
                        Textarea::make('rejection_remark')
                            ->label('Rejection Remark')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(fn (Order $record, array $data) => $record->reject(
                        auth()->id(),
                        $data['rejection_remark'],
                    )),
                Action::make('dispatch')
                    ->label('Mark as Dispatched')
                    ->color('info')
                    ->visible(fn (Order $record): bool => $record->status === 'approved'
                        && auth()->user()?->canActAsProductionSupervisor()
                        && Gate::forUser(auth()->user())->allows('dispatch', $record))
                    ->authorize(fn (Order $record): bool => Gate::forUser(auth()->user())->allows('dispatch', $record))
                    ->form([
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
                        );
                    }),
                Action::make('cancel')
                    ->label('Cancel')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Order $record): bool => ! $ordersOnlyUser() && $record->canTransitionTo('cancelled'))
                    ->action(fn (Order $record) => $record->transitionTo('cancelled')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->visible(fn (): bool => ! $ordersOnlyUser()),
                    ForceDeleteBulkAction::make()->visible(fn (): bool => ! $ordersOnlyUser()),
                    RestoreBulkAction::make()->visible(fn (): bool => ! $ordersOnlyUser()),
                ]),
            ]);
    }
}
