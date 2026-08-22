<?php

namespace App\Filament\Pages;

use App\Exports\Dealers\EmployeeOutstandingExport;
use App\Filament\Resources\Dealers\DealerResource;
use App\Models\Dealer;
use App\Services\Dealers\DealerAccessService;
use App\Services\Dealers\DealerLedgerService;
use App\Services\Dealers\DealerOutstandingService;
use App\Support\IndianCurrency;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TotalOutstanding extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static string|\UnitEnum|null $navigationGroup = 'Sales Operations';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Total Outstanding';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $title = 'Total Outstanding';

    protected static ?string $slug = 'total-outstanding';

    protected string $view = 'filament.pages.total-outstanding';

    protected Width|string|null $maxContentWidth = Width::Full;

    /** @var array<string, mixed> */
    public array $data = [];

    #[Url(as: 'employee_id', history: true, keep: true, except: null)]
    public ?int $employeeId = null;

    /** @var array<string, mixed>|null */
    private ?array $dashboardCache = null;

    private ?int $dashboardCacheEmployeeId = -1;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if ($user === null || ! $user->usesAdminDirectorDashboard()) {
            return false;
        }

        return app(DealerAccessService::class)->canViewAnyLedger($user);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        if ($this->employeeId !== null && $this->employeeId <= 0) {
            $this->employeeId = null;
        }

        $this->form->fill([
            'employee_id' => $this->employeeId,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('employee_id')
                    ->label('Employee')
                    ->placeholder('All Employees')
                    ->options(fn (): array => app(DealerOutstandingService::class)->salesEmployeeOptions())
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->nullable()
                    ->live()
                    ->afterStateUpdated(function (mixed $state): void {
                        $this->employeeId = filled($state) ? (int) $state : null;
                        $this->forgetDashboardCache();
                        $this->resetTable();
                    }),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportPdf')
                ->label('Export PDF')
                ->icon(Heroicon::OutlinedDocumentChartBar)
                ->color('gray')
                ->url(fn (): string => static::pdfUrl($this->selectedEmployeeId()))
                ->openUrlInNewTab(),
            Action::make('exportExcel')
                ->label('Export Excel')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('success')
                ->action(fn () => $this->exportExcel()),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Dealer Outstanding')
            ->description(fn (): string => $this->selectedEmployeeId() !== null
                ? 'Assigned dealers for the selected employee.'
                : 'Dealers with a debit outstanding or credit balance.')
            ->query(fn (): Builder => $this->dealersQuery())
            ->columns([
                TextColumn::make('dealer_code')
                    ->label('Dealer Code')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('firm_name')
                    ->label('Dealer Name')
                    ->searchable()
                    ->sortable()
                    ->weight(FontWeight::SemiBold),
                TextColumn::make('village')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('assignedEmployee.full_name')
                    ->label('Employee')
                    ->placeholder('Unassigned')
                    ->sortable(),
                TextColumn::make('current_outstanding')
                    ->label('Outstanding')
                    ->state(function (Dealer $record): float {
                        return $this->dealerBalances($record)['outstanding'];
                    })
                    ->formatStateUsing(fn ($state): string => IndianCurrency::format((float) $state))
                    ->alignEnd()
                    ->weight(FontWeight::Bold)
                    ->extraCellAttributes(['class' => 'to-amt to-amt--out'])
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        $sql = DealerLedgerService::currentOutstandingSql($query->getModel()->getTable());

                        return $query->orderByRaw(
                            '(CASE WHEN '.$sql.' > 0 THEN '.$sql.' ELSE 0 END) '.$direction
                        );
                    }),
                TextColumn::make('credit_balance')
                    ->label('Credit Balance')
                    ->state(function (Dealer $record): float {
                        return $this->dealerBalances($record)['credit'];
                    })
                    ->formatStateUsing(fn ($state): string => (float) $state > 0
                        ? IndianCurrency::format((float) $state)
                        : '-')
                    ->alignEnd()
                    ->extraCellAttributes(['class' => 'to-amt to-amt--credit'])
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        $sql = DealerLedgerService::currentOutstandingSql($query->getModel()->getTable());

                        return $query->orderByRaw(
                            '(CASE WHEN '.$sql.' < 0 THEN -('.$sql.') ELSE 0 END) '.$direction
                        );
                    }),
            ])
            ->recordActions([
                Action::make('ledger')
                    ->label('Ledger')
                    ->icon(Heroicon::OutlinedBookOpen)
                    ->color('gray')
                    ->url(fn (Dealer $record): string => DealerResource::getUrl('ledger', ['record' => $record]))
                    ->visible(fn (Dealer $record): bool => auth()->user()?->can('viewLedger', $record) ?? false),
            ])
            ->recordUrl(fn (Dealer $record): ?string => auth()->user()?->can('viewLedger', $record)
                ? DealerResource::getUrl('ledger', ['record' => $record])
                : DealerResource::getUrl('view', ['record' => $record]))
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->striped()
            ->emptyStateHeading(fn (): string => $this->selectedEmployeeId() !== null
                ? 'No assigned dealers'
                : 'No dealers with outstanding')
            ->emptyStateDescription(fn (): string => $this->selectedEmployeeId() !== null
                ? 'This employee has no active assigned dealers.'
                : 'No dealer has a debit outstanding or credit balance for this filter.')
            ->contentFooter(function () {
                $summary = $this->balanceSummary();

                return view('filament.pages.partials.total-outstanding-table-footer', [
                    'total' => $this->formatMoney($summary['outstanding']),
                    'credit' => $this->formatMoney($summary['credit']),
                    'net' => $this->formatMoney($summary['net']),
                    'showCredit' => $summary['credit'] > 0,
                    'columnCount' => 7,
                ]);
            });
    }

    public function selectEmployee(?int $employeeId): void
    {
        $this->employeeId = $employeeId !== null && $employeeId > 0 ? $employeeId : null;

        $this->form->fill([
            'employee_id' => $this->employeeId,
        ]);

        $this->forgetDashboardCache();
        $this->resetTable();
    }

    public function resetFilter(): void
    {
        $this->selectEmployee(null);
    }

    public function selectedEmployeeId(): ?int
    {
        $value = $this->employeeId ?? ($this->data['employee_id'] ?? null);

        return filled($value) ? (int) $value : null;
    }

    public function totalOutstanding(): float
    {
        return $this->balanceSummary()['outstanding'];
    }

    public function formattedTotalOutstanding(): string
    {
        return $this->formatMoney($this->totalOutstanding());
    }

    public function formattedCreditBalance(): string
    {
        return $this->formatMoney($this->balanceSummary()['credit']);
    }

    public function outstandingDealerCount(): int
    {
        return (int) ($this->balanceSummary()['outstanding_dealers'] ?? 0);
    }

    public function highOutstandingDealerCount(): int
    {
        return (int) $this->dashboard()['highOutstanding'];
    }

    /**
     * @return array{outstanding: float, credit: float, net: float, outstanding_dealers: int}
     */
    public function balanceSummary(): array
    {
        /** @var array{outstanding: float, credit: float, net: float, outstanding_dealers: int} $summary */
        $summary = $this->dashboard()['summary'];

        return $summary;
    }

    /**
     * @return list<array{employee_id: int, employee_name: string, employee_code: string|null, dealer_count: int, outstanding_dealer_count: int, total_outstanding: float, total_credit: float, net_balance: float}>
     */
    public function employeeOutstandingRows(): array
    {
        /** @var list<array{employee_id: int, employee_name: string, employee_code: string|null, dealer_count: int, outstanding_dealer_count: int, total_outstanding: float, total_credit: float, net_balance: float}> $rows */
        $rows = $this->dashboard()['employees'];

        return $rows;
    }

    /**
     * @return list<array{dealer_name: string, employee_name: string, outstanding: float}>
     */
    public function topOutstandingDealers(): array
    {
        /** @var list<array{dealer_name: string, employee_name: string, outstanding: float}> $rows */
        $rows = $this->dashboard()['topDealers'];

        return $rows;
    }

    public function formatMoney(float $amount): string
    {
        return IndianCurrency::format($amount);
    }

    /**
     * @return array{summary: array<string, mixed>, employees: list<array<string, mixed>>, topDealers: list<array<string, mixed>>, highOutstanding: int}
     */
    private function dashboard(): array
    {
        $employeeId = $this->selectedEmployeeId();

        if ($this->dashboardCache !== null && $this->dashboardCacheEmployeeId === $employeeId) {
            return $this->dashboardCache;
        }

        $service = app(DealerOutstandingService::class);

        $this->dashboardCacheEmployeeId = $employeeId;
        $this->dashboardCache = [
            'summary' => $service->summary($employeeId),
            'employees' => $service->totalsByAssignedEmployee(),
            'topDealers' => $service->topOutstandingDealers($employeeId, 5),
            'highOutstanding' => $service->highOutstandingDealerCount($employeeId),
        ];

        return $this->dashboardCache;
    }

    private function forgetDashboardCache(): void
    {
        $this->dashboardCache = null;
        $this->dashboardCacheEmployeeId = -1;
    }

    /**
     * @return array{outstanding: float, credit: float}
     */
    private function dealerBalances(Dealer $record): array
    {
        $value = $record->getAttribute('current_outstanding');
        $net = $value !== null
            ? round((float) $value, 2)
            : app(DealerLedgerService::class)->getOutstanding($record);

        return app(DealerOutstandingService::class)->splitBalances($net);
    }

    public static function pdfUrl(?int $employeeId = null): string
    {
        return route('filament.admin.total-outstanding.pdf', array_filter(
            ['employee_id' => $employeeId],
            fn (mixed $value): bool => $value !== null,
        ));
    }

    public function exportExcel(): BinaryFileResponse
    {
        $payload = app(DealerOutstandingService::class)->exportPayload($this->selectedEmployeeId());

        return Excel::download(
            new EmployeeOutstandingExport(
                payload: $payload,
                generatedAt: now('Asia/Kolkata')->format('d M Y, h:i A'),
            ),
            $this->exportFilename($payload, 'xlsx'),
        );
    }

    /**
     * @param  array{employee_name: string, employee_code: string|null}  $payload
     */
    private function exportFilename(array $payload, string $extension): string
    {
        $scope = Str::slug($payload['employee_name'] ?? 'all-employees') ?: 'all-employees';

        return sprintf(
            'Total_Outstanding_%s_%s.%s',
            $scope,
            now('Asia/Kolkata')->format('Y-m-d'),
            $extension,
        );
    }

    /**
     * @return Builder<Dealer>
     */
    private function dealersQuery(): Builder
    {
        $employeeId = $this->selectedEmployeeId();
        $service = app(DealerOutstandingService::class);

        if ($employeeId !== null) {
            return $service->assignedDealersQuery($employeeId);
        }

        return $service->dealersQuery(null);
    }
}
