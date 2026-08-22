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
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;
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
                Section::make('Filters')
                    ->compact()
                    ->schema([
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
                                $this->resetTable();
                            }),
                    ]),
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
                ->visible(fn (): bool => $this->selectedEmployeeId() !== null)
                ->action(fn () => $this->exportPdf()),
            Action::make('exportExcel')
                ->label('Export Excel')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('success')
                ->visible(fn (): bool => $this->selectedEmployeeId() !== null)
                ->action(fn () => $this->exportExcel()),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(fn (): string => $this->selectedEmployeeId() !== null
                ? 'Assigned Dealers'
                : 'Dealer-wise Outstanding')
            ->description(fn (): string => $this->selectedEmployeeId() !== null
                ? 'All parties assigned to the selected employee, with current outstanding.'
                : 'Dealers with a positive outstanding balance.')
            ->query(fn (): Builder => $this->dealersQuery())
            ->columns([
                TextColumn::make('dealer_code')
                    ->label('Dealer Code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('firm_name')
                    ->label('Dealer Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('village')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('assignedEmployee.full_name')
                    ->label('Employee')
                    ->placeholder('Unassigned')
                    ->visible(fn (): bool => $this->selectedEmployeeId() === null)
                    ->sortable(),
                TextColumn::make('current_outstanding')
                    ->label('Outstanding')
                    ->state(function (Dealer $record): float {
                        $value = $record->getAttribute('current_outstanding');

                        return $value !== null
                            ? round((float) $value, 2)
                            : app(DealerLedgerService::class)->getOutstanding($record);
                    })
                    ->formatStateUsing(fn ($state): string => IndianCurrency::format((float) $state))
                    ->alignEnd()
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderByRaw(
                            DealerLedgerService::currentOutstandingSql($query->getModel()->getTable()).' '.$direction
                        );
                    }),
            ])
            ->recordActions([
                Action::make('ledger')
                    ->label('Ledger')
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
                : 'No dealer has a positive outstanding balance for this filter.')
            ->contentFooter(function () {
                if ($this->selectedEmployeeId() === null) {
                    return null;
                }

                return view('filament.pages.partials.total-outstanding-table-footer', [
                    'total' => $this->formattedTotalOutstanding(),
                    'columnCount' => 5,
                ]);
            });
    }

    public function selectEmployee(?int $employeeId): void
    {
        $this->employeeId = $employeeId !== null && $employeeId > 0 ? $employeeId : null;

        $this->form->fill([
            'employee_id' => $this->employeeId,
        ]);

        $this->resetTable();
    }

    public function selectedEmployeeId(): ?int
    {
        $value = $this->employeeId ?? ($this->data['employee_id'] ?? null);

        return filled($value) ? (int) $value : null;
    }

    public function totalOutstanding(): float
    {
        return app(DealerOutstandingService::class)->total($this->selectedEmployeeId());
    }

    public function formattedTotalOutstanding(): string
    {
        return IndianCurrency::format($this->totalOutstanding());
    }

    public function assignedDealerCount(): int
    {
        $employeeId = $this->selectedEmployeeId();

        if ($employeeId === null) {
            return 0;
        }

        return app(DealerOutstandingService::class)->assignedDealersQuery($employeeId)->count();
    }

    /**
     * @return list<array{employee_id: int, employee_name: string, employee_code: string|null, dealer_count: int, total_outstanding: float}>
     */
    public function employeeOutstandingRows(): array
    {
        return app(DealerOutstandingService::class)->totalsByAssignedEmployee();
    }

    public function formatMoney(float $amount): string
    {
        return IndianCurrency::format($amount);
    }

    public function exportExcel(): ?BinaryFileResponse
    {
        $payload = $this->exportPayloadOrNotify();

        if ($payload === null) {
            return null;
        }

        return Excel::download(
            new EmployeeOutstandingExport(
                payload: $payload,
                generatedAt: now('Asia/Kolkata')->format('d M Y, h:i A'),
            ),
            $this->exportFilename($payload, 'xlsx'),
        );
    }

    public function exportPdf(): ?Response
    {
        $payload = $this->exportPayloadOrNotify();

        if ($payload === null) {
            return null;
        }

        return Pdf::loadView('filament.pages.employee-outstanding-pdf', [
            'companyName' => (string) config('app.name', 'ParamGold ERP'),
            'payload' => $payload,
            'generatedAt' => now('Asia/Kolkata')->format('d M Y, h:i A'),
        ])->download($this->exportFilename($payload, 'pdf'));
    }

    /**
     * @return array{employee_name: string, employee_code: string|null, total: float, rows: list<array<string, mixed>>}|null
     */
    private function exportPayloadOrNotify(): ?array
    {
        $employeeId = $this->selectedEmployeeId();

        if ($employeeId === null) {
            Notification::make()
                ->title('Select an employee to export.')
                ->warning()
                ->send();

            return null;
        }

        return app(DealerOutstandingService::class)->employeeExportPayload($employeeId);
    }

    /**
     * @param  array{employee_name: string, employee_code: string|null}  $payload
     */
    private function exportFilename(array $payload, string $extension): string
    {
        return sprintf(
            'Employee_Outstanding_%s_%s.%s',
            Str::slug($payload['employee_name']) ?: 'employee',
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
