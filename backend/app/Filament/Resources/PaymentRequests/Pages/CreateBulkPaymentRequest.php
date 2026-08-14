<?php

namespace App\Filament\Resources\PaymentRequests\Pages;

use App\Actions\PaymentRequests\BulkCreatePaymentRequests;
use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
use App\Models\PaymentRequest;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

class CreateBulkPaymentRequest extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $resource = PaymentRequestResource::class;

    protected static ?string $title = 'Create Bulk Payment Request';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'bulk-create';

    protected string $view = 'filament.resources.payment-requests.create-bulk-payment-request';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(array $parameters = []): bool
    {
        return PaymentRequestResource::canAccess()
            && auth()->user()?->can('create', PaymentRequest::class);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->fill([
            'rows' => [
                [
                    'vendor_name' => '',
                    'vendor_mobile' => '',
                    'amount' => null,
                    'remark' => '',
                ],
            ],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Bulk Payment Requests')
                    ->description('Each row becomes its own Payment Request with a unique request number and status history.')
                    ->schema([
                        Repeater::make('rows')
                            ->label('Payment Requests')
                            ->minItems(1)
                            ->maxItems(100)
                            ->defaultItems(1)
                            ->addActionLabel('Add Row')
                            ->reorderable(false)
                            ->columns(4)
                            ->live()
                            ->schema([
                                TextInput::make('vendor_name')
                                    ->label('Vendor Name')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpan(1),
                                TextInput::make('vendor_mobile')
                                    ->label('Vendor Mobile')
                                    ->required()
                                    ->tel()
                                    ->maxLength(20)
                                    ->rules(['regex:/^[0-9+\-\s]{8,20}$/'])
                                    ->columnSpan(1),
                                TextInput::make('amount')
                                    ->label('Amount ₹')
                                    ->required()
                                    ->numeric()
                                    ->minValue(0.01)
                                    ->prefix('₹')
                                    ->columnSpan(1),
                                Textarea::make('remark')
                                    ->label('Remark')
                                    ->rows(1)
                                    ->maxLength(2000)
                                    ->columnSpan(1),
                            ]),
                    ]),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Back to Payment Requests')
                ->icon('heroicon-o-arrow-left')
                ->url(PaymentRequestResource::getUrl('index')),
        ];
    }

    public function getRequestCountProperty(): int
    {
        $rows = $this->data['rows'] ?? [];

        return is_array($rows) ? count($rows) : 0;
    }

    public function getTotalAmountProperty(): float
    {
        $rows = $this->data['rows'] ?? [];
        if (! is_array($rows)) {
            return 0.0;
        }

        $total = 0.0;
        foreach ($rows as $row) {
            $total += (float) ($row['amount'] ?? 0);
        }

        return round($total, 2);
    }

    public function submit(): void
    {
        abort_unless(static::canAccess(), 403);

        try {
            $state = $this->form->getState();
            $rows = $state['rows'] ?? [];
            $created = app(BulkCreatePaymentRequests::class)->execute(auth()->user(), $rows);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Bulk create failed')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Bulk payment requests created')
            ->body($created->count().' request(s) submitted for first approval (Krishna Rajbinde).')
            ->success()
            ->send();

        $this->redirect(PaymentRequestResource::getUrl('index'));
    }
}
