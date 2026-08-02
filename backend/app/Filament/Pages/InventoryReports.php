<?php

namespace App\Filament\Pages;

use App\Enums\StockItemType;
use App\Exports\Inventory\InventoryReportExport;
use App\Filament\Concerns\InventoryFilamentAccess;
use App\Services\Inventory\InventoryReportResult;
use App\Services\Inventory\InventoryReportService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\ArrayRecord;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Unified Inventory Stock Report — a single ERP-standard view combining
 * Raw Material, Packaging Material, Semi Finished (reserved), and Finished
 * Product stock. Reads existing stock/valuation fields only; no calculation,
 * posting, WAC, BOM, production, or ledger logic lives here.
 */
class InventoryReports extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;
    use InventoryFilamentAccess;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory & Manufacturing';

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Inventory Stock Report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static ?string $title = 'Inventory Stock Report';

    protected static ?string $slug = 'inventory-reports';

    protected string $view = 'filament.pages.inventory-reports';

    protected Width|string|null $maxContentWidth = Width::Full;

    /** @var array<string, mixed> */
    public array $data = [];

    public string $generatedAt = '';

    /**
     * Stock-status filter applied by Low Stock / Out of Stock cards (and URL).
     */
    #[Url(as: 'stock_status', history: true, keep: true, except: null)]
    public ?string $stockStatusFilter = null;

    #[Url(as: 'inventory_type', history: true, keep: true, except: InventoryReportService::TYPE_ALL)]
    public string $urlInventoryType = InventoryReportService::TYPE_ALL;

    #[Url(as: 'item_id', history: true, keep: true, except: null)]
    public ?int $urlItemId = null;

    #[Url(as: 'search', history: true, keep: true, except: '')]
    public ?string $urlSearch = null;

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $inventoryType = $this->urlInventoryType;
        if (! array_key_exists($inventoryType, InventoryReportService::inventoryTypeOptions())) {
            $inventoryType = InventoryReportService::TYPE_ALL;
            $this->urlInventoryType = $inventoryType;
        }

        $itemKey = null;
        if ($this->urlItemId !== null && $this->urlItemId > 0 && $inventoryType !== InventoryReportService::TYPE_ALL) {
            $itemKey = $inventoryType.':'.$this->urlItemId;
        }

        if ($this->stockStatusFilter !== null && ! in_array($this->stockStatusFilter, ['low_stock', 'out_of_stock'], true)) {
            $this->stockStatusFilter = null;
        }

        $this->form->fill([
            'inventory_type' => $inventoryType,
            'item_key' => $itemKey,
            'search' => $this->urlSearch !== null && $this->urlSearch !== '' ? $this->urlSearch : null,
        ]);

        $this->touchGeneratedAt();
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
                        'xl' => 3,
                    ])
                    ->schema([
                        Select::make('inventory_type')
                            ->label('Inventory Type')
                            ->options(InventoryReportService::inventoryTypeOptions())
                            ->required()
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('item_key', null);
                                $this->urlItemId = null;
                                $this->syncUrlFromForm();
                                $this->refreshReportView();
                            }),
                        Select::make('item_key')
                            ->label('Item / Product')
                            ->searchable()
                            ->preload(false)
                            ->native(false)
                            ->options(fn (Get $get): array => app(InventoryReportService::class)
                                ->itemOptions((string) ($get('inventory_type') ?: InventoryReportService::TYPE_ALL)))
                            ->live()
                            ->afterStateUpdated(function (): void {
                                $this->syncUrlFromForm();
                                $this->refreshReportView();
                            }),
                        TextInput::make('search')
                            ->label('Search')
                            ->placeholder('Search by item name')
                            ->live(debounce: 500)
                            ->afterStateUpdated(function (): void {
                                $this->syncUrlFromForm();
                                $this->refreshReportView();
                            }),
                    ]),
            ])
            ->statePath('data');
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(fn (): string => $this->buildReport()->title)
            ->description(fn (): Htmlable => $this->tableDescription($this->buildReport()))
            ->records(function (
                ?string $sortColumn,
                ?string $sortDirection,
                ?string $search,
                int|string $page,
                int|string $recordsPerPage,
            ): LengthAwarePaginator {
                return $this->paginateReportRecords(
                    sortColumn: $sortColumn,
                    sortDirection: $sortDirection,
                    page: max(1, (int) $page),
                    recordsPerPage: max(1, min(100, (int) $recordsPerPage)),
                );
            })
            ->columns($this->makeReportColumns($this->buildReport()))
            ->recordActions($this->makeLedgerRecordActions())
            ->headerActions([
                Action::make('applyFilters')
                    ->label('Apply')
                    ->icon(Heroicon::OutlinedFunnel)
                    ->color('primary')
                    ->action(fn () => $this->applyFilters()),
                Action::make('resetFilters')
                    ->label('Reset')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->action(fn () => $this->resetFilters()),
                Action::make('exportReport')
                    ->label('Export Excel')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('success')
                    ->action(fn () => $this->exportReport()),
            ])
            ->striped()
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->defaultSort('item_name', 'asc')
            ->emptyStateHeading('No data available')
            ->emptyStateDescription('No stock items match the selected filters.')
            ->emptyStateIcon(Heroicon::OutlinedDocumentChartBar)
            ->contentFooter(function () {
                $report = $this->buildReport();

                if (! $this->canViewCostColumns()) {
                    return null;
                }

                $total = $report->totalStockValueFooter();

                if ($total === null) {
                    return null;
                }

                return view('filament.pages.partials.inventory-reports-table-footer', [
                    'total' => $total,
                ]);
            })
            ->extremePaginationLinks();
    }

    public function applyFilters(): void
    {
        $this->syncUrlFromForm();
        $this->refreshReportView();
    }

    public function resetFilters(): void
    {
        $this->form->fill([
            'inventory_type' => InventoryReportService::TYPE_ALL,
            'item_key' => null,
            'search' => null,
        ]);

        $this->stockStatusFilter = null;
        $this->urlInventoryType = InventoryReportService::TYPE_ALL;
        $this->urlItemId = null;
        $this->urlSearch = null;
        $this->refreshReportView();
    }

    /**
     * Total Stock Value card — show full inventory.
     */
    public function filterTotalStock(): void
    {
        $this->applyInventoryTypeCard(InventoryReportService::TYPE_ALL);
    }

    /**
     * Raw Material Value card.
     */
    public function filterRawMaterialStock(): void
    {
        $this->applyInventoryTypeCard(InventoryReportService::TYPE_RAW_MATERIAL);
    }

    /**
     * Packaging Material Value card.
     */
    public function filterPackagingMaterialStock(): void
    {
        $this->applyInventoryTypeCard(InventoryReportService::TYPE_PACKAGING_MATERIAL);
    }

    /**
     * Finished Product Value card.
     */
    public function filterFinishedProductStock(): void
    {
        $this->applyInventoryTypeCard(InventoryReportService::TYPE_FINISHED_PRODUCT);
    }

    /**
     * Low Stock Items card — keep inventory type; apply low-stock status.
     */
    public function filterLowStock(): void
    {
        $this->stockStatusFilter = 'low_stock';
        $this->syncUrlFromForm();
        $this->refreshReportView();
    }

    /**
     * Out of Stock Items card — keep inventory type; apply out-of-stock status.
     */
    public function filterOutOfStock(): void
    {
        $this->stockStatusFilter = 'out_of_stock';
        $this->syncUrlFromForm();
        $this->refreshReportView();
    }

    /**
     * Which value-type summary card is active (total / raw / packaging), if any.
     */
    public function activeValueCardFilter(): ?string
    {
        $type = (string) ($this->data['inventory_type'] ?? InventoryReportService::TYPE_ALL);

        return match ($type) {
            InventoryReportService::TYPE_ALL => 'total',
            InventoryReportService::TYPE_RAW_MATERIAL => InventoryReportService::TYPE_RAW_MATERIAL,
            InventoryReportService::TYPE_PACKAGING_MATERIAL => InventoryReportService::TYPE_PACKAGING_MATERIAL,
            InventoryReportService::TYPE_FINISHED_PRODUCT => InventoryReportService::TYPE_FINISHED_PRODUCT,
            default => null,
        };
    }

    public function isSummaryCardActive(string $filter): bool
    {
        if (in_array($filter, ['low_stock', 'out_of_stock'], true)) {
            return $this->stockStatusFilter === $filter;
        }

        return $this->activeValueCardFilter() === $filter;
    }

    protected function applyInventoryTypeCard(string $inventoryType): void
    {
        $this->form->fill([
            'inventory_type' => $inventoryType,
            'item_key' => null,
            'search' => null,
        ]);

        $this->stockStatusFilter = null;
        $this->urlInventoryType = $inventoryType;
        $this->urlItemId = null;
        $this->urlSearch = null;
        $this->refreshReportView();
    }

    protected function syncUrlFromForm(): void
    {
        $type = (string) ($this->data['inventory_type'] ?? InventoryReportService::TYPE_ALL);
        if (! array_key_exists($type, InventoryReportService::inventoryTypeOptions())) {
            $type = InventoryReportService::TYPE_ALL;
        }

        $this->urlInventoryType = $type;
        $this->urlSearch = filled($this->data['search'] ?? null) ? trim((string) $this->data['search']) : null;

        $itemKey = $this->data['item_key'] ?? null;
        $this->urlItemId = null;

        if (is_string($itemKey) && str_contains($itemKey, ':')) {
            [$itemType, $id] = explode(':', $itemKey, 2);
            if ($itemType === $type && is_numeric($id) && (int) $id > 0) {
                $this->urlItemId = (int) $id;
            }
        }
    }

    protected function refreshReportView(): void
    {
        $this->resetPage();
        $this->flushCachedTableRecords();
        $this->touchGeneratedAt();
    }

    public function exportReport(): BinaryFileResponse
    {
        $filters = $this->activeFilters();
        $report = app(InventoryReportService::class)->build($filters);

        if (! $this->canViewCostColumns()) {
            $report = $this->stripCostColumnsFromReport($report);
        }

        return Excel::download(
            new InventoryReportExport(
                report: $report,
                generatedAt: $this->generatedAt ?: now('Asia/Kolkata')->format('d M Y, h:i A'),
            ),
            $report->filenameStem.'_'.now('Asia/Kolkata')->format('Y-m-d').'.xlsx',
        );
    }

    public function getReportProperty(): InventoryReportResult
    {
        $report = $this->buildReport();

        if (! $this->canViewCostColumns()) {
            return $this->stripCostFromSummaryCards($report);
        }

        return $report;
    }

    protected function paginateReportRecords(
        ?string $sortColumn,
        ?string $sortDirection,
        int $page,
        int $recordsPerPage,
    ): LengthAwarePaginator {
        $filters = $this->activeFilters();
        $report = app(InventoryReportService::class)->build($filters);

        $paginator = $report->paginate(
            perPage: $recordsPerPage,
            sortBy: $sortColumn ?: 'item_name',
            sortDirection: $sortDirection ?: $report->defaultSortDirection,
            page: $page,
        );

        $start = ($paginator->currentPage() - 1) * $paginator->perPage();
        $items = [];
        $index = $start;

        foreach ($paginator->items() as $record) {
            $index++;
            $values = ($report->rowMapper)($record, $index);
            $row = [ArrayRecord::getKeyName() => (string) $index];

            foreach ($report->columns as $offset => $column) {
                if (! $this->shouldShowReportColumn($column)) {
                    continue;
                }

                $row[$column['key']] = $values[$offset] ?? null;
            }

            if (is_object($record) && isset($record->item_id, $record->inventory_type_key) && filled($record->inventory_type_key)) {
                $row['item_id'] = (int) $record->item_id;
                $row['item_type'] = (string) $record->inventory_type_key;
            }

            $items[(string) $index] = $row;
        }

        return new LengthAwarePaginator(
            items: $items,
            total: $paginator->total(),
            perPage: $paginator->perPage(),
            currentPage: $paginator->currentPage(),
            options: [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'pageName' => $this->getTablePaginationPageName(),
            ],
        );
    }

    /**
     * @return list<Action>
     */
    protected function makeLedgerRecordActions(): array
    {
        return [
            Action::make('viewLedger')
                ->label('View Ledger')
                ->icon(Heroicon::OutlinedBookOpen)
                ->color('gray')
                ->url(function (array $record): ?string {
                    $itemId = (int) ($record['item_id'] ?? 0);
                    $itemType = (string) ($record['item_type'] ?? '');

                    if ($itemId < 1 || $itemType === '' || StockItemType::tryFrom($itemType) === null) {
                        return null;
                    }

                    return StockItemLedger::urlForItem(
                        itemType: $itemType,
                        itemId: $itemId,
                    );
                })
                ->visible(fn (array $record): bool => StockItemType::tryFrom((string) ($record['item_type'] ?? '')) !== null),
        ];
    }

    /**
     * @return list<TextColumn>
     */
    protected function makeReportColumns(InventoryReportResult $report): array
    {
        $columns = [];

        foreach ($this->visibleReportColumns($report) as $column) {
            $textColumn = TextColumn::make($column['key'])
                ->label($column['label'])
                ->alignment(match ($column['align']) {
                    'right' => Alignment::End,
                    'center' => Alignment::Center,
                    default => Alignment::Start,
                });

            if ($column['sortable'] !== false) {
                $textColumn->sortable();
            }

            $textColumn = match ($column['format']) {
                'qty' => $textColumn
                    ->formatStateUsing(fn (mixed $state): string => $this->formatQtyCell($state))
                    ->extraAttributes(['class' => 'tabular-nums']),
                'money' => $textColumn
                    ->formatStateUsing(fn (mixed $state): string => $this->formatMoneyCell($state))
                    ->extraAttributes(['class' => 'tabular-nums']),
                'rate' => $textColumn
                    ->formatStateUsing(fn (mixed $state): string => $this->formatRateCell($state))
                    ->extraAttributes(['class' => 'tabular-nums']),
                'integer' => $textColumn
                    ->formatStateUsing(fn (mixed $state): string => $state === null || $state === ''
                        ? '—'
                        : number_format((int) $state))
                    ->extraAttributes(['class' => 'tabular-nums']),
                'badge_stock' => $textColumn
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => match ((string) $state) {
                        'out_of_stock' => 'Out of Stock',
                        'low_stock' => 'Low Stock',
                        'in_stock' => 'In Stock',
                        default => (string) ($state ?: '—'),
                    })
                    ->color(fn (mixed $state): string => match ((string) $state) {
                        'out_of_stock' => 'danger',
                        'low_stock' => 'warning',
                        'in_stock' => 'success',
                        default => 'gray',
                    }),
                'badge_type' => $textColumn
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => (string) ($state ?: '—'))
                    ->color(fn (mixed $state): string => match ((string) $state) {
                        'Raw Material' => 'info',
                        'Packaging Material' => 'warning',
                        'Finished Product' => 'success',
                        'Semi Finished' => 'gray',
                        default => 'gray',
                    }),
                default => $textColumn
                    ->formatStateUsing(fn (mixed $state): string => $state === null || $state === ''
                        ? '—'
                        : (string) $state),
            };

            $columns[] = $textColumn;
        }

        return $columns;
    }

    /**
     * @return list<array{key: string, label: string, align: string, format: string, sortable: string|false}>
     */
    protected function visibleReportColumns(InventoryReportResult $report): array
    {
        return array_values(array_filter(
            $report->columns,
            fn (array $column): bool => $this->shouldShowReportColumn($column),
        ));
    }

    /**
     * @param  array{key: string, label: string, align: string, format: string, sortable: string|false}  $column
     */
    protected function shouldShowReportColumn(array $column): bool
    {
        if ($this->canViewCostColumns()) {
            return true;
        }

        return ! in_array($column['format'], ['money', 'rate'], true);
    }

    protected function canViewCostColumns(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return $user->canViewInwardRates() || $user->canViewProductionCosts();
    }

    protected function stripCostColumnsFromReport(InventoryReportResult $report): InventoryReportResult
    {
        $keptIndexes = [];
        $columns = [];

        foreach ($report->columns as $index => $column) {
            if (! $this->shouldShowReportColumn($column)) {
                continue;
            }

            $keptIndexes[] = $index;
            $columns[] = $column;
        }

        $originalMapper = $report->rowMapper;

        return new InventoryReportResult(
            title: $report->title,
            filenameStem: $report->filenameStem,
            columns: $columns,
            summaryCards: $this->stripCostSummaryCards($report->summaryCards),
            appliedFilterLabels: $report->appliedFilterLabels,
            query: $report->query,
            rowMapper: function (object $record, int $sr) use ($originalMapper, $keptIndexes): array {
                $values = $originalMapper($record, $sr);
                $filtered = [];

                foreach ($keptIndexes as $index) {
                    $filtered[] = $values[$index] ?? null;
                }

                return $filtered;
            },
            defaultSort: $report->defaultSort,
            defaultSortDirection: $report->defaultSortDirection,
            footerStockValue: null,
            footerBreakdown: null,
        );
    }

    protected function stripCostFromSummaryCards(InventoryReportResult $report): InventoryReportResult
    {
        return new InventoryReportResult(
            title: $report->title,
            filenameStem: $report->filenameStem,
            columns: $report->columns,
            summaryCards: $this->stripCostSummaryCards($report->summaryCards),
            appliedFilterLabels: $report->appliedFilterLabels,
            query: $report->query,
            rowMapper: $report->rowMapper,
            defaultSort: $report->defaultSort,
            defaultSortDirection: $report->defaultSortDirection,
            footerStockValue: $report->footerStockValue,
            footerBreakdown: $report->footerBreakdown,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $cards
     * @return list<array<string, mixed>>
     */
    protected function stripCostSummaryCards(array $cards): array
    {
        return array_values(array_filter(
            $cards,
            fn (array $card): bool => ! str_contains(strtolower($card['label']), 'value')
                && ! str_contains(strtolower($card['label']), 'rate')
                && ! str_contains(strtolower($card['label']), 'cost')
                && ! str_contains(strtolower($card['label']), 'amount'),
        ));
    }

    protected function tableDescription(InventoryReportResult $report): Htmlable
    {
        $filters = count($report->appliedFilterLabels)
            ? implode(' · ', $report->appliedFilterLabels)
            : 'None';

        return new HtmlString(
            '<span class="text-xs text-gray-500 dark:text-gray-400">'
            .'Generated on: '.e($this->generatedAt)
            .' &nbsp;|&nbsp; Applied filters: '.e($filters)
            .'</span>'
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function activeFilters(): array
    {
        return [
            'inventory_type' => $this->data['inventory_type'] ?? InventoryReportService::TYPE_ALL,
            'item_key' => $this->data['item_key'] ?? null,
            'search' => $this->data['search'] ?? null,
            'stock_status_filter' => $this->stockStatusFilter,
        ];
    }

    protected function buildReport(): InventoryReportResult
    {
        $filters = $this->activeFilters();
        // Request-scoped memoization — table heading/columns/footer/records all call this.
        $cacheKey = 'filament.inventory_report.'.md5((string) json_encode($filters));

        if (app()->bound($cacheKey)) {
            return app($cacheKey);
        }

        $report = app(InventoryReportService::class)->build($filters);
        app()->instance($cacheKey, $report);

        return $report;
    }

    protected function touchGeneratedAt(): void
    {
        $this->generatedAt = Carbon::now('Asia/Kolkata')->format('d M Y, h:i A');
    }

    protected function formatQtyCell(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return rtrim(rtrim(number_format((float) $value, 3, '.', ','), '0'), '.') ?: '0';
    }

    protected function formatMoneyCell(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return '₹'.number_format((float) $value, 2);
    }

    protected function formatRateCell(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return number_format((float) $value, 2);
    }
}
