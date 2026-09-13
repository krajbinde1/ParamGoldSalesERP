<?php

namespace App\Filament\Resources\Dealers\Pages;

use App\Actions\Dealers\RemoveTallyLedgerEntry;
use App\Exceptions\TallyMappingException;
use App\Filament\Pages\PossibleDuplicateSales;
use App\Filament\Resources\Dealers\DealerResource;
use App\Models\Dealer;
use App\Models\DealerTallyEntry;
use App\Models\TallyConnectorLedger;
use App\Services\TallyLedger\TallyDealerLedgerService;
use App\Services\TallyLedger\TallyLedgerImportService;
use App\Services\TallySync\TallyConnectorStatusService;
use App\Services\TallySync\TallyDealerMappingService;
use App\Services\TallySync\TallyLiveBalanceService;
use App\Support\IndianCurrency;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Size;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;

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

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return ['pg-dealer-ledger-page'];
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtraBodyAttributes(): array
    {
        return [
            'class' => 'pg-dealer-ledger-page',
        ];
    }

    protected function getHeaderActions(): array
    {
        $canImport = $this->canManageTallyLedger();
        $mapping = $this->mappingStatus();
        $isAdmin = auth()->user()?->isAdminUser() ?? false;

        return [
            Action::make('syncLiveTally')
                ->label('Sync Live Tally Now')
                ->icon('heroicon-o-signal')
                ->visible(fn (): bool => $isAdmin)
                ->action(function (): void {
                    app(TallyLiveBalanceService::class)->requestSync();
                    $online = app(TallyConnectorStatusService::class)->isConnected();
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
            ActionGroup::make([
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
                    ->modalHeading('Remove Mapping')
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
            ])
                ->label('Tally mapping')
                ->buttonGroup()
                ->dropdown(false)
                ->view('filament.resources.dealers.partials.dealer-ledger-mapping-actions')
                ->extraAttributes(['class' => 'pg-dealer-ledger-mapping-actions'])
                ->visible(fn (): bool => $isAdmin),
            Action::make('duplicateSalesReport')
                ->label('Duplicate Sales Report')
                ->icon('heroicon-o-document-chart-bar')
                ->url(fn (): string => PossibleDuplicateSales::getUrl([
                    'dealer_id' => $this->getRecord()->getKey(),
                ]))
                ->visible(fn (): bool => PossibleDuplicateSales::canAccess()),
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

    public function canManageTallyLedger(): bool
    {
        $user = auth()->user();

        return ($user?->isAdminUser() ?? false) || ($user?->isDirectorUser() ?? false);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function removedTallyAuditRows(): array
    {
        if (! $this->canManageTallyLedger()) {
            return [];
        }

        /** @var Dealer $dealer */
        $dealer = $this->getRecord();

        return DealerTallyEntry::query()
            ->onlyRemoved()
            ->where('dealer_id', $dealer->id)
            ->with('removedBy:id,name')
            ->orderByDesc('removed_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (DealerTallyEntry $entry): array {
                $debit = round((float) $entry->debit, 2);
                $credit = round((float) $entry->credit, 2);
                $isDebit = $debit > 0;

                return [
                    'voucher_no' => filled($entry->voucher_no) ? (string) $entry->voucher_no : '—',
                    'date' => $entry->entry_date?->toDateString(),
                    'side' => $isDebit ? 'Debit' : 'Credit',
                    'amount_label' => IndianCurrency::formatExact($isDebit ? $debit : $credit),
                    'reason' => (string) $entry->removal_reason,
                    'removed_by' => $entry->removedBy?->name ?? '—',
                    'removed_at' => $entry->removed_at?->timezone('Asia/Kolkata')->format('d M Y • h:i A'),
                    'source_label' => DealerTallyEntry::sourceLabel((string) $entry->source, $entry->voucher_type),
                ];
            })
            ->all();
    }

    public function removeTallyEntryAction(): Action
    {
        return Action::make('removeTallyEntry')
            ->label('Remove Tally Entry')
            ->color('danger')
            ->link()
            ->size(Size::ExtraSmall)
            ->visible(fn (): bool => $this->canManageTallyLedger())
            ->authorize(fn (): bool => $this->canManageTallyLedger())
            ->modalHeading('Remove Tally Entry')
            ->modalDescription('This removes the voucher from ERP ledger calculation only. Actual Tally data is not changed.')
            ->modalSubmitActionLabel('Remove Tally Entry')
            ->fillForm(function (Action $action): array {
                $entry = $this->findRemovableTallyEntry((int) ($action->getArguments()['entryId'] ?? 0));
                if ($entry === null) {
                    return [];
                }

                $debit = round((float) $entry->debit, 2);
                $credit = round((float) $entry->credit, 2);
                $isDebit = $debit > 0;

                return [
                    'voucher_no' => filled($entry->voucher_no) ? (string) $entry->voucher_no : '—',
                    'entry_date' => $entry->entry_date?->format('d M Y') ?? '—',
                    'side' => $isDebit ? 'Debit' : 'Credit',
                    'amount' => IndianCurrency::formatExact($isDebit ? $debit : $credit),
                ];
            })
            ->form([
                TextInput::make('voucher_no')
                    ->label('Voucher No.')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('entry_date')
                    ->label('Date')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('side')
                    ->label('Debit/Credit')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('amount')
                    ->label('Amount')
                    ->disabled()
                    ->dehydrated(false),
                Textarea::make('reason')
                    ->label('Reason')
                    ->required()
                    ->minLength(3)
                    ->maxLength(2000)
                    ->rows(3)
                    ->helperText('Explain why this Tally-imported voucher should be excluded from ERP outstanding.'),
            ])
            ->action(function (array $data, array $arguments): void {
                $entry = $this->findRemovableTallyEntry((int) ($arguments['entryId'] ?? 0));
                if ($entry === null) {
                    Notification::make()
                        ->danger()
                        ->title('Tally entry not found')
                        ->send();

                    return;
                }

                try {
                    app(RemoveTallyLedgerEntry::class)->execute(
                        $entry,
                        auth()->user(),
                        (string) ($data['reason'] ?? ''),
                    );
                } catch (AuthorizationException $exception) {
                    Notification::make()
                        ->danger()
                        ->title($exception->getMessage())
                        ->send();

                    return;
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->danger()
                        ->title(collect($exception->errors())->flatten()->first() ?: 'This entry cannot be removed.')
                        ->send();

                    return;
                }

                $this->refreshRecord();

                Notification::make()
                    ->success()
                    ->title('Tally entry removed')
                    ->body('ERP outstanding was recalculated. Actual Tally data was not changed.')
                    ->send();
            });
    }

    private function findRemovableTallyEntry(int $entryId): ?DealerTallyEntry
    {
        if ($entryId <= 0) {
            return null;
        }

        return DealerTallyEntry::query()
            ->where('dealer_id', $this->getRecord()->getKey())
            ->whereKey($entryId)
            ->first();
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
        $emptyMessage = $mappings->ledgerCatalogEmptyMessage();

        return [
            Select::make('tally_ledger_guid')
                ->label('Tally Ledger')
                ->placeholder($emptyMessage ?? 'Search Tally ledger name')
                ->searchable()
                ->preload()
                ->optionsLimit(5000)
                ->options(fn (): array => $mappings->searchLedgers('', 5000))
                ->getSearchResultsUsing(fn (string $search): array => $mappings->searchLedgers($search, 5000))
                ->getOptionLabelUsing(function (?string $value) use ($mappings): ?string {
                    if (! filled($value)) {
                        return null;
                    }
                    $ledger = TallyConnectorLedger::query()->where('tally_ledger_guid', $value)->first();

                    return $ledger ? $mappings->ledgerLabel($ledger) : $value;
                })
                ->searchPrompt('Type the Tally ledger name')
                ->noSearchResultsMessage($emptyMessage ?? 'No Tally ledger matches that name.')
                ->helperText($emptyMessage ?? 'Select the exact Tally ledger. Its GUID is saved. This does not change ledger transactions or outstanding.')
                ->disabled(fn (): bool => $emptyMessage !== null)
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
