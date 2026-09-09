<?php

namespace App\Filament\Pages;

use App\Models\Dealer;
use App\Models\Employee;
use App\Services\PaymentFollowUps\PaymentFollowUpPerformanceService;
use App\Services\PaymentFollowUps\PaymentFollowUpService;
use App\Services\PaymentFollowUps\PaymentFollowUpStatus;
use App\Support\IndianCurrency;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class PaymentFollowUps extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static string|\UnitEnum|null $navigationGroup = 'Sales Operations';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Payment Follow-up';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?string $title = 'Payment Follow-up';

    protected static ?string $slug = 'payment-follow-up';

    protected string $view = 'filament.pages.payment-follow-ups';

    protected Width|string|null $maxContentWidth = Width::Full;

    /** @var array<string, mixed> */
    public array $data = [];

    #[Url(as: 'dealer_id', history: true)]
    public ?int $dealerId = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if ($user === null || $user->hasOrdersOnlyFilamentAccess()) {
            return false;
        }

        return $user->usesAdminDirectorDashboard() || $user->isAdminUser();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        if ($this->dealerId !== null && $this->dealerId <= 0) {
            $this->dealerId = null;
        }

        $this->form->fill($this->data);
    }

    public function updatedData(): void
    {
        $this->resetTable();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Filters')
                    ->compact()
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                        'xl' => 4,
                    ])
                    ->schema([
                        Select::make('employee_id')
                            ->label('Employee')
                            ->placeholder('All Employees')
                            ->options(fn (): array => Employee::query()
                                ->where('status', true)
                                ->orderBy('full_name')
                                ->get()
                                ->mapWithKeys(fn (Employee $employee): array => [
                                    $employee->id => $employee->displayLabel(),
                                ])
                                ->all())
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->nullable()
                            ->live()
                            ->afterStateUpdated(fn () => $this->resetTable()),
                        Select::make('dealer_filter_id')
                            ->label('Dealer')
                            ->placeholder('All Dealers')
                            ->options(fn (): array => Dealer::query()
                                ->where('status', true)
                                ->whereNotNull('assigned_employee_id')
                                ->orderBy('firm_name')
                                ->pluck('firm_name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->nullable()
                            ->live()
                            ->afterStateUpdated(fn () => $this->resetTable()),
                        Select::make('status')
                            ->label('Status')
                            ->placeholder('All Statuses')
                            ->options(PaymentFollowUpStatus::labels())
                            ->native(false)
                            ->nullable()
                            ->live()
                            ->afterStateUpdated(fn () => $this->resetTable()),
                        DatePicker::make('from_date')
                            ->label('From Date')
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(fn () => $this->resetTable()),
                        DatePicker::make('to_date')
                            ->label('To Date')
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(fn () => $this->resetTable()),
                    ]),
            ])
            ->statePath('data');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->dealersQuery())
            ->columns([
                TextColumn::make('assignedEmployee.full_name')
                    ->label('Employee Name')
                    ->placeholder('-')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('firm_name')
                    ->label('Dealer Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('village')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('current_outstanding')
                    ->label('Current Outstanding')
                    ->state(fn (Dealer $record): float => $this->row($record)['current_outstanding'])
                    ->formatStateUsing(fn ($state): string => IndianCurrency::format((float) $state))
                    ->alignEnd(),
                TextColumn::make('last_follow_up_date')
                    ->label('Last Follow-up Date')
                    ->state(fn (Dealer $record): ?string => $this->row($record)['last_follow_up_date'])
                    ->placeholder('-')
                    ->date('d M Y'),
                TextColumn::make('last_remark')
                    ->label('Last Remark')
                    ->state(fn (Dealer $record): ?string => $this->row($record)['last_remark'])
                    ->limit(40)
                    ->placeholder('-')
                    ->wrap(),
                TextColumn::make('expected_amount')
                    ->label('Expected Amount')
                    ->state(fn (Dealer $record): ?float => $this->row($record)['expected_amount'])
                    ->formatStateUsing(fn ($state): string => $state !== null ? IndianCurrency::format((float) $state) : '-')
                    ->alignEnd(),
                TextColumn::make('next_follow_up_date')
                    ->label('Next Follow-up Date')
                    ->state(fn (Dealer $record): ?string => $this->row($record)['next_follow_up_date'])
                    ->placeholder('-')
                    ->date('d M Y'),
                TextColumn::make('follow_up_status')
                    ->label('Status')
                    ->state(fn (Dealer $record): string => $this->row($record)['status_label'])
                    ->badge()
                    ->color(fn (Dealer $record): string => match ($this->row($record)['status']) {
                        PaymentFollowUpStatus::OVERDUE => 'danger',
                        PaymentFollowUpStatus::DUE_TODAY => 'warning',
                        PaymentFollowUpStatus::UPCOMING => 'info',
                        PaymentFollowUpStatus::CLOSED => 'success',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('assigned_employee_id')
                    ->label('Employee')
                    ->relationship('assignedEmployee', 'full_name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordUrl(fn (Dealer $record): string => static::getUrl(['dealer_id' => $record->id]))
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->striped()
            ->emptyStateHeading('No assigned dealers')
            ->emptyStateDescription('Payment Follow-up uses existing dealer assignments. No separate dealer master is used here.');
    }

    public function dashboardCounts(): array
    {
        return app(PaymentFollowUpPerformanceService::class)->dashboardCounts(
            $this->filterEmployeeId(),
            $this->filterDealerId(),
        );
    }

    public function formatMoney(float $amount): string
    {
        return IndianCurrency::format($amount);
    }

    public function timelineDealer(): ?Dealer
    {
        if ($this->dealerId === null) {
            return null;
        }

        return Dealer::query()
            ->with(['assignedEmployee:id,full_name'])
            ->find($this->dealerId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function timelineDetail(): ?array
    {
        $dealer = $this->timelineDealer();
        if ($dealer === null) {
            return null;
        }

        return app(PaymentFollowUpService::class)->dealerDetail($dealer);
    }

    public function closeTimeline(): void
    {
        $this->dealerId = null;
    }

    /**
     * @return Builder<Dealer>
     */
    private function dealersQuery(): Builder
    {
        $service = app(PaymentFollowUpService::class);
        $query = $service->adminDealersQuery();

        if ($this->filterEmployeeId()) {
            $query->where('assigned_employee_id', $this->filterEmployeeId());
        }

        if ($this->filterDealerId()) {
            $query->whereKey($this->filterDealerId());
        }

        $service->applyStatusFilter($query, $this->filterStatus());

        $from = $this->data['from_date'] ?? null;
        $to = $this->data['to_date'] ?? null;

        if (filled($from) || filled($to)) {
            $query->whereHas('latestPaymentFollowUpEntry', function (Builder $entry) use ($from, $to): void {
                if (filled($from)) {
                    $entry->whereDate('followed_up_at', '>=', $from);
                }
                if (filled($to)) {
                    $entry->whereDate('followed_up_at', '<=', $to);
                }
            });
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Dealer $record): array
    {
        return app(PaymentFollowUpService::class)->listRow($record);
    }

    private function filterEmployeeId(): ?int
    {
        $value = $this->data['employee_id'] ?? null;

        return filled($value) ? (int) $value : null;
    }

    private function filterDealerId(): ?int
    {
        $value = $this->data['dealer_filter_id'] ?? null;

        return filled($value) ? (int) $value : null;
    }

    private function filterStatus(): ?string
    {
        $value = $this->data['status'] ?? null;

        return filled($value) ? (string) $value : null;
    }
}
