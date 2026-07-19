<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Actions\Orders\DispatchOrderWithTransport;
use App\Enums\TransportType;
use App\Filament\Resources\Orders\OrderResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
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
            Action::make('dispatch')
                ->label('Mark as Dispatched')
                ->color('info')
                ->visible(fn (): bool => $record->status === 'approved'
                    && auth()->user()?->canActAsProductionSupervisor()
                    && Gate::forUser(auth()->user())->allows('dispatch', $record))
                ->authorize(fn (): bool => Gate::forUser(auth()->user())->allows('dispatch', $record))
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
                ->action(function (array $data) use ($record): void {
                    app(DispatchOrderWithTransport::class)->execute(
                        order: $record,
                        actor: auth()->user(),
                        transportType: $data['transport_type'],
                        transportAmount: (float) $data['transport_amount'],
                        remark: $data['dispatch_remark'] ?? null,
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
