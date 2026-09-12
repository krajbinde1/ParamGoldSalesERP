<?php

namespace App\Filament\Resources\Dealers\Pages;

use App\Exceptions\TallyMappingException;
use App\Filament\Resources\Dealers\DealerResource;
use App\Models\Dealer;
use App\Models\TallyConnectorLedger;
use App\Services\TallyLedger\TallyDealerLedgerService;
use App\Services\TallyLedger\TallyLedgerImportService;
use App\Services\TallySync\TallyDealerMappingService;
use App\Services\TallySync\TallyLiveBalanceService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
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
        $mapping = $this->mappingStatus();
        $isAdmin = auth()->user()?->isAdminUser() ?? false;

        return [
            Action::make('syncLiveTally')
                ->label('Sync Live Tally Now')
                ->icon('heroicon-o-signal')
                ->visible(fn (): bool => $isAdmin)
                ->action(function (): void {
                    $state = app(TallyLiveBalanceService::class)->requestSync();
                    $online = $state->tallyIsOnline();
                    $notification = Notification::make()
                        ->title($online ? 'Live Tally sync requested' : 'Tally connector is offline')
                        ->body($online
                            ? 'The office Tally connector will push current closing balances on its next poll. Ledger transactions are not changed.'
                            : 'Start the Tally connector on the Tally PC. ERP will not call Tally from this page.');

                    if ($online) {
                        $notification->success()->send();

                        return;
                    }

                    $notification->warning()->send();
                }),
            Action::make('mapTallyLedger')
                ->label('Map Tally Ledger')
                ->icon('heroicon-o-link')
                ->visible(function () use ($isAdmin, $mapping): bool {
                    return $isAdmin && ! $mapping['has_guid'] && ! $mapping['mapped'];
                })
                ->modalHeading('Map Tally Ledger')
                ->modalDescription('Search and select the exact Tally ledger. This saves its GUID permanently and does not change ledger transactions or ERP outstanding.')
                ->form($this->tallyLedgerSelectForm())
                ->action(function (array $data): void {
                    $this->saveTallyMapping((string) ($data['tally_ledger_guid'] ?? ''), overwrite: false);
                }),
            Action::make('changeTallyMapping')
                ->label('Change Mapping')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(function () use ($isAdmin, $mapping): bool {
                    return $isAdmin && $mapping['mapped'];
                })
                ->requiresConfirmation()
                ->modalHeading('Change Tally Mapping')
                ->modalDescription('Replace the saved Tally ledger GUID. Ledger transactions and ERP outstanding will not change. Confirm this is the correct ledger.')
                ->form($this->tallyLedgerSelectForm())
                ->action(function (array $data): void {
                    $this->saveTallyMapping((string) ($data['tally_ledger_guid'] ?? ''), overwrite: true);
                }),
            Action::make('removeTallyMapping')
                ->label('Remove Mapping')
                ->icon('heroicon-o-x-mark')
                ->color('danger')
                ->visible(function () use ($isAdmin, $mapping): bool {
                    return $isAdmin && $mapping['has_guid'];
                })
                ->requiresConfirmation()
                ->modalHeading('Remove Tally Mapping')
                ->modalDescription('The saved Tally GUID will be removed. Historical ledger entries and ERP outstanding will not change.')
                ->modalSubmitActionLabel('Remove Mapping')
                ->action(function (): void {
                    /** @var Dealer $dealer */
                    $dealer = $this->getRecord();
                    app(TallyDealerMappingService::class)->remove($dealer);
                    $this->refreshRecord();
                    Notification::make()
                        ->success()
                        ->title('Tally mapping removed')
                        ->body('This dealer can be mapped again. Ledger transactions were not changed.')
                        ->send();
                }),
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

    /**
     * @return array<string, mixed>
     */
    private function mappingStatus(): array
    {
        /** @var Dealer $dealer */
        $dealer = $this->getRecord();

        return app(TallyDealerMappingService::class)->status($dealer);
    }

    /**
     * @return list<Select>
     */
    private function tallyLedgerSelectForm(): array
    {
        $mappings = app(TallyDealerMappingService::class);

        return [
            Select::make('tally_ledger_guid')
                ->label('Tally Ledger')
                ->placeholder('Search Tally ledger name')
                ->searchable()
                ->preload(false)
                ->options(fn (): array => $mappings->searchLedgers('', 30))
                ->getSearchResultsUsing(fn (string $search): array => $mappings->searchLedgers($search))
                ->getOptionLabelUsing(function (?string $value) use ($mappings): ?string {
                    if (! filled($value)) {
                        return null;
                    }
                    $ledger = TallyConnectorLedger::query()->where('tally_ledger_guid', $value)->first();

                    return $ledger ? $mappings->ledgerLabel($ledger) : $value;
                })
                ->helperText('Only exact Tally ledgers from the latest Live Tally sync are listed. Run Sync Live Tally Now if this list is empty.')
                ->required(),
        ];
    }

    private function saveTallyMapping(string $guid, bool $overwrite): void
    {
        /** @var Dealer $dealer */
        $dealer = $this->getRecord();

        try {
            $mapping = app(TallyDealerMappingService::class)->assign(
                $dealer,
                $guid,
                auth()->id(),
                $overwrite,
            );
        } catch (TallyMappingException $exception) {
            Notification::make()
                ->danger()
                ->title('Tally mapping not saved')
                ->body($exception->getMessage())
                ->send();

            return;
        }

        $this->refreshRecord();
        Notification::make()
            ->success()
            ->title($overwrite ? 'Tally mapping changed' : 'Tally ledger mapped')
            ->body('Saved GUID for '.$mapping->tally_ledger_name.'. Ledger transactions and ERP outstanding were not changed.')
            ->send();
    }

    private function refreshRecord(): void
    {
        $this->getRecord()->refresh();
        $this->getRecord()->unsetRelations();
    }
}
