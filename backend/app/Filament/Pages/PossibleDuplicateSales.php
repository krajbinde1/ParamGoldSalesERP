<?php

namespace App\Filament\Pages;

use App\Models\Dealer;
use App\Services\Dealers\DealerAccessService;
use App\Services\Dealers\DealerSalesLedgerReconciler;
use App\Support\IndianCurrency;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

class PossibleDuplicateSales extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Sales Operations';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Duplicate Sales Report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static ?string $title = 'Possible Duplicate ERP Sales + Tally Sales';

    protected static ?string $slug = 'possible-duplicate-sales';

    protected string $view = 'filament.pages.possible-duplicate-sales';

    protected Width|string|null $maxContentWidth = Width::Full;

    #[Url(as: 'dealer_id', history: true, keep: true)]
    public ?int $dealerId = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if ($user === null || ! $user->usesAdminDirectorDashboard()) {
            return false;
        }

        return app(DealerAccessService::class)->canViewAnyLedger($user);
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
    }

    public function updatedDealerId(mixed $value): void
    {
        $id = is_numeric($value) ? (int) $value : 0;
        $this->dealerId = $id > 0 ? $id : null;
    }

    /**
     * @return array<int, string>
     */
    public function dealerOptions(): array
    {
        return Dealer::query()
            ->orderBy('firm_name')
            ->pluck('firm_name', 'id')
            ->all();
    }

    public function selectedDealer(): ?Dealer
    {
        if ($this->dealerId === null) {
            return null;
        }

        return Dealer::query()->find($this->dealerId);
    }

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        return app(DealerSalesLedgerReconciler::class)->duplicateSalesReport($this->selectedDealer());
    }

    public function formatMoney(float|int|string|null $amount): string
    {
        return IndianCurrency::formatExact($amount);
    }

    public function formatOutstanding(float|int|string|null $signed): string
    {
        return IndianCurrency::formatDrCr($signed);
    }
}
