<?php

namespace App\Filament\Resources\Dealers\Pages;

use App\Filament\Resources\Dealers\DealerResource;
use App\Models\Dealer;
use App\Services\Dealers\DealerLedgerService;
use Filament\Actions\Action;
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
        return [
            Action::make('backToDealer')
                ->label('Back to Dealer')
                ->url(DealerResource::getUrl('view', ['record' => $this->getRecord()])),
        ];
    }

    /**
     * @return array{summary: array<string, mixed>, ledger: list<array<string, mixed>>}
     */
    public function ledgerPayload(): array
    {
        /** @var Dealer $record */
        $record = $this->getRecord();

        return app(DealerLedgerService::class)->getLedger($record);
    }
}
