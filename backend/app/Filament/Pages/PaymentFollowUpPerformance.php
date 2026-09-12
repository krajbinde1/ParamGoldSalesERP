<?php

namespace App\Filament\Pages;

use App\Services\PaymentFollowUps\PaymentFollowUpPerformanceService;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

class PaymentFollowUpPerformance extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\UnitEnum|null $navigationGroup = 'Sales Operations';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Follow-up Performance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $title = 'Payment Follow-up Performance';

    protected static ?string $slug = 'payment-follow-up-performance';

    protected string $view = 'filament.pages.payment-follow-up-performance';

    protected Width|string|null $maxContentWidth = Width::Full;

    /** @var array<string, mixed> */
    public array $data = [];

    #[Url(as: 'from_date', history: true)]
    public ?string $fromDate = null;

    #[Url(as: 'to_date', history: true)]
    public ?string $toDate = null;

    public static function canAccess(): bool
    {
        return PaymentFollowUps::canAccess();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->fill([
            'from_date' => $this->fromDate,
            'to_date' => $this->toDate,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Date range for completed follow-ups / payments received')
                    ->compact()
                    ->columns(2)
                    ->schema([
                        DatePicker::make('from_date')
                            ->label('From Date')
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(function (?string $state): void {
                                $this->fromDate = $state;
                            }),
                        DatePicker::make('to_date')
                            ->label('To Date')
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(function (?string $state): void {
                                $this->toDate = $state;
                            }),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        return app(PaymentFollowUpPerformanceService::class)->employeeRows(
            $this->fromDate ?: ($this->data['from_date'] ?? null),
            $this->toDate ?: ($this->data['to_date'] ?? null),
        );
    }
}
