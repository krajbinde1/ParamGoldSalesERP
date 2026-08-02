<?php

namespace App\Filament\Pages;

use App\Enums\StockItemType;
use App\Exports\Inventory\StockItemLedgerExport;
use App\Filament\Concerns\InventoryFilamentAccess;
use App\Models\PackagingMaterial;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Models\SemiFinishedMaterial;
use App\Services\Inventory\StockItemLedgerResult;
use App\Services\Inventory\StockItemLedgerService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StockItemLedger extends Page
{
    use InventoryFilamentAccess;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory & Manufacturing';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Stock Ledger';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $title = 'Item Stock Ledger';

    protected static ?string $slug = 'stock-item-ledger';

    protected string $view = 'filament.pages.stock-item-ledger';

    protected Width|string|null $maxContentWidth = Width::Full;

    /** @var array<string, mixed> */
    public array $data = [];

    public bool $applied = false;

    public bool $itemLocked = false;

    public ?string $sourceReportType = null;

    public ?string $appliedFrom = null;

    public ?string $appliedTo = null;

    public int $page = 1;

    public int $perPage = 50;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    /**
     * Preferred deep-link into an item ledger from Inventory Reports.
     */
    public static function urlForItem(string $itemType, int $itemId, ?string $reportType = null): string
    {
        $slug = match ($itemType) {
            StockItemType::PackagingMaterial->value => 'packaging-material',
            StockItemType::SemiFinished->value => 'semi-finished',
            StockItemType::FinishedProduct->value => 'finished-product',
            default => 'raw-material',
        };

        $query = array_filter([
            'report_type' => $reportType,
        ], fn ($value): bool => filled($value));

        return url('/admin/inventory-reports/ledger/'.$slug.'/'.$itemId)
            .($query !== [] ? '?'.http_build_query($query) : '');
    }

    public static function reportTypeForItemType(string $itemType): string
    {
        return match ($itemType) {
            StockItemType::PackagingMaterial->value => 'packaging_material_stock',
            StockItemType::SemiFinished->value => 'semi_finished_stock',
            StockItemType::FinishedProduct->value => 'finished_goods_stock',
            default => 'raw_material_stock',
        };
    }

    public static function stockReportUrl(?string $reportType = null): string
    {
        $url = InventoryReports::getUrl();
        $reportType = $reportType ?: 'raw_material_stock';

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query([
            'report_type' => $reportType,
        ]);
    }

    public static function normalizeItemTypeSlug(string $slug): ?string
    {
        return match ($slug) {
            'raw-material', StockItemType::RawMaterial->value => StockItemType::RawMaterial->value,
            'packaging-material', StockItemType::PackagingMaterial->value => StockItemType::PackagingMaterial->value,
            'semi-finished', StockItemType::SemiFinished->value => StockItemType::SemiFinished->value,
            'finished-product', StockItemType::FinishedProduct->value => StockItemType::FinishedProduct->value,
            default => null,
        };
    }

    public function mount(?string $itemType = null, ?int $itemId = null): void
    {
        abort_unless(static::canAccess(), 403);

        $resolvedType = self::normalizeItemTypeSlug(
            (string) ($itemType ?: request()->query('item_type', '')),
        );
        $resolvedId = $itemId ?: (int) request()->query('item_id', 0);
        $reportType = request()->query('report_type');

        if (! is_string($reportType) || $reportType === '') {
            $reportType = $resolvedType ? self::reportTypeForItemType($resolvedType) : null;
        }

        if (! $resolvedType || $resolvedId < 1) {
            $this->redirect(InventoryReports::getUrl());

            return;
        }

        if (! $this->itemExists($resolvedType, $resolvedId)) {
            Notification::make()
                ->title('Item not found')
                ->body('The selected stock item could not be loaded for the ledger.')
                ->danger()
                ->send();

            $this->redirect(self::stockReportUrl($reportType ?: 'raw_material_stock'));

            return;
        }

        $this->itemLocked = true;
        $this->sourceReportType = is_string($reportType) ? $reportType : self::reportTypeForItemType($resolvedType);
        $this->applied = true;

        $dates = self::defaultDateRange();
        $this->data = [
            'item_type' => $resolvedType,
            'item_id' => $resolvedId,
            ...$dates,
        ];
        $this->appliedFrom = $dates['from'];
        $this->appliedTo = $dates['to'];
    }

    /**
     * @return array{from: string, to: string}
     */
    public static function defaultDateRange(): array
    {
        return [
            'from' => now('Asia/Kolkata')->startOfMonth()->toDateString(),
            'to' => now('Asia/Kolkata')->toDateString(),
        ];
    }

    public function getHeading(): string|Htmlable
    {
        return 'Item Stock Ledger';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToReport')
                ->label('Back to Stock Report')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(fn (): string => self::stockReportUrl(
                    $this->sourceReportType
                        ?: self::reportTypeForItemType((string) ($this->data['item_type'] ?? StockItemType::RawMaterial->value)),
                )),
            Action::make('exportExcel')
                ->label('Export Excel')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('success')
                ->disabled(fn (): bool => ! $this->shouldShowLedger())
                ->action(fn () => $this->exportExcel()),
            Action::make('print')
                ->label('Print')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->disabled(fn (): bool => ! $this->shouldShowLedger())
                ->url(fn (): ?string => $this->printUrl())
                ->openUrlInNewTab(),
        ];
    }

    public function applyFilters(): void
    {
        $from = $this->data['from'] ?? null;
        $to = $this->data['to'] ?? null;

        if (! filled($from) || ! filled($to)) {
            Notification::make()
                ->title('Date range required')
                ->body('Select both From Date and To Date, then click Apply Filter.')
                ->danger()
                ->send();

            return;
        }

        if ((string) $from > (string) $to) {
            Notification::make()
                ->title('Invalid date range')
                ->body('From Date cannot be after To Date.')
                ->danger()
                ->send();

            return;
        }

        $this->appliedFrom = (string) $from;
        $this->appliedTo = (string) $to;
        $this->page = 1;
        $this->applied = true;
    }

    public function nextPage(): void
    {
        $result = $this->ledgerResult();
        if ($this->page < $result->lastPage()) {
            $this->page++;
        }
    }

    public function previousPage(): void
    {
        if ($this->page > 1) {
            $this->page--;
        }
    }

    public function gotoPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    public function hasItemSelected(): bool
    {
        return filled($this->data['item_id'] ?? null) && filled($this->data['item_type'] ?? null);
    }

    public function shouldShowLedger(): bool
    {
        return $this->applied && $this->hasItemSelected();
    }

    public function canViewCostColumns(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return $user->canViewInwardRates() || $user->canViewProductionCosts();
    }

    public function ledgerResult(): StockItemLedgerResult
    {
        if (! $this->shouldShowLedger()) {
            return app(StockItemLedgerService::class)->build($this->filterPayload(), requireItem: false);
        }

        return app(StockItemLedgerService::class)->build([
            ...$this->filterPayload(),
            'page' => $this->page,
            'per_page' => $this->perPage,
        ], requireItem: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function filterPayload(): array
    {
        $defaults = self::defaultDateRange();

        return [
            'item_type' => $this->data['item_type'] ?? null,
            'item_id' => $this->data['item_id'] ?? null,
            'from' => $this->appliedFrom ?? $this->data['from'] ?? $defaults['from'],
            'to' => $this->appliedTo ?? $this->data['to'] ?? $defaults['to'],
            'transaction_type' => null,
            'voucher_number' => null,
            'supplier' => null,
            'production_batch' => null,
            'inward_only' => false,
            'outward_only' => false,
        ];
    }

    public function exportExcel(): BinaryFileResponse
    {
        if (! $this->hasItemSelected()) {
            throw ValidationException::withMessages([
                'item_id' => 'Select an item before exporting.',
            ]);
        }

        $filters = $this->filterPayload();
        $summary = app(StockItemLedgerService::class)->build([
            ...$filters,
            'page' => 1,
            'per_page' => 1,
        ], requireItem: true);

        $itemSlug = Str::slug($summary->header['item_code'] ?: $summary->header['item_name'] ?: 'item');
        $filename = sprintf(
            'Stock_Ledger_%s_%s_to_%s.xlsx',
            $itemSlug,
            $summary->header['from'],
            $summary->header['to'],
        );

        return Excel::download(
            new StockItemLedgerExport(
                filters: $filters,
                summary: $summary,
                companyName: (string) config('app.name', 'Param Gold Sales ERP'),
            ),
            $filename,
        );
    }

    public function printUrl(): ?string
    {
        if (! $this->hasItemSelected()) {
            return null;
        }

        return route('inventory.stock-item-ledger.print', array_filter(
            $this->filterPayload(),
            fn ($value): bool => $value !== null && $value !== '',
        ));
    }

    protected function itemExists(string $itemType, int $itemId): bool
    {
        return match ($itemType) {
            StockItemType::RawMaterial->value => RawMaterial::query()->whereKey($itemId)->exists(),
            StockItemType::PackagingMaterial->value => PackagingMaterial::query()->whereKey($itemId)->exists(),
            StockItemType::SemiFinished->value => SemiFinishedMaterial::query()->whereKey($itemId)->exists(),
            StockItemType::FinishedProduct->value => Product::query()->whereKey($itemId)->exists(),
            default => false,
        };
    }

    public static function formatQty(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return number_format((float) $value, 3, '.', ',');
    }

    public static function formatRate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return '₹'.number_format((float) $value, 2, '.', ',');
    }

    public static function formatMoney(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return '₹'.number_format((float) $value, 2, '.', ',');
    }
}
