<?php

namespace App\Filament\Resources\Boms\Pages;

use App\Filament\Resources\Boms\BomResource;
use App\Models\Bom;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;

class ViewBom extends ViewRecord
{
    protected static string $resource = BomResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->getRecord()->loadMissing([
            'product',
            'semiFinished',
            'items.rawMaterial',
            'items.packagingMaterial',
            'items.semiFinished',
        ]);
    }

    public function getTitle(): string|Htmlable
    {
        /** @var Bom $record */
        $record = $this->getRecord();

        return (string) ($record->bom_number ?: 'Bill of Material');
    }

    public function getHeader(): ?View
    {
        /** @var Bom $record */
        $record = $this->getRecord();

        return view('filament.resources.boms.partials.bom-view-header', [
            'record' => $record,
            'actions' => $this->getCachedHeaderActions(),
            'actionsAlignment' => $this->getHeaderActionsAlignment(),
            'breadcrumbs' => filament()->hasBreadcrumbs() ? $this->getBreadcrumbs() : [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtraBodyAttributes(): array
    {
        return [
            'class' => 'pg-bom-view-page',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->authorize(fn (): bool => BomResource::canEdit($this->getRecord())),
        ];
    }
}
