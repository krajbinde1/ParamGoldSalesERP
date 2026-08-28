<?php

namespace App\Filament\Resources\Dealers\Pages;

use App\Filament\Resources\Dealers\DealerResource;
use App\Models\Dealer;
use App\Services\TallyLedger\TallyDealerLedgerService;
use App\Services\TallyLedger\TallyLedgerImportService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewDealerLedger extends ViewRecord
{
    protected static string $resource = DealerResource::class;

    protected string $view = 'filament.resources.dealers.pages.view-dealer-ledger';

    public function mount(int|string $record): void
    {
        parent::mount($record);

        abort_unless(
            auth()->user()?->can('viewLedger', $this->getRecord()) ?? false,
            403
        );
    }

    public function getTitle(): string|Htmlable
    {
        /** @var Dealer $record */
        $record = $this->getRecord();

        return 'Dealer Ledger — '.$record->firm_name;
    }

    protected function getHeaderActions(): array
    {
        $canImport = (auth()->user()?->isAdminUser() ?? false) || (auth()->user()?->isDirectorUser() ?? false);

        return [
            Action::make('importTallyLedger')
                ->label('Import Tally Ledger')
                ->icon('heroicon-o-arrow-up-tray')
                ->url(fn (): string => DealerResource::getUrl('import-tally-ledger', ['record' => $this->getRecord()]))
                ->visible(fn (): bool => $canImport),
            Action::make('resetTallyLedger')
                ->label('Reset Tally Ledger')
                ->icon('heroicon-o-arrow-path')
                ->color('danger')
                ->visible(fn (): bool => $canImport && $this->dealerHasTallyImportData())
                ->requiresConfirmation()
                ->modalHeading('Reset Tally Ledger')
                ->modalDescription('This will remove only the Tally-imported ledger data for this dealer. Continue?')
                ->modalSubmitActionLabel('Reset Tally Ledger')
                ->action(function (): void {
                    /** @var Dealer $dealer */
                    $dealer = $this->getRecord();
                    app(TallyLedgerImportService::class)->resetForDealer($dealer);
                    $dealer->refresh();
                    $dealer->unsetRelations();

                    Notification::make()
                        ->success()
                        ->title('Tally ledger reset')
                        ->body('Tally-imported ledger data was removed for this dealer. You can import the Excel again.')
                        ->send();
                }),
            Action::make('backToDealer')
                ->label('Back to Dealer')
                ->url(DealerResource::getUrl('view', ['record' => $this->getRecord()])),
        ];
    }

    /**
     * @return array{summary: array<string, mixed>, ledger: list<array<string, mixed>>, verification: array<string, mixed>}
     */
    public function ledgerPayload(): array
    {
        /** @var Dealer $record */
        $record = $this->getRecord();

        return app(TallyDealerLedgerService::class)->statement($record);
    }

    private function dealerHasTallyImportData(): bool
    {
        /** @var Dealer $dealer */
        $dealer = $this->getRecord();

        return $dealer->tallyLedger()->exists()
            || $dealer->tallyEntries()->exists()
            || $dealer->tallyImports()->exists();
    }
}
