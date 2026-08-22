<?php

namespace App\Filament\Resources\Dealers\Pages;

use App\Filament\Actions\SafeDeleteActions;
use App\Filament\Resources\Dealers\DealerResource;
use App\Support\MaharashtraGeography;
use Filament\Actions\Action;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditDealer extends EditRecord
{
    protected static string $resource = DealerResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return MaharashtraGeography::canonicalizeLocationFields($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = MaharashtraGeography::canonicalizeLocationFields($data);
        $data['opening_balance_type'] = in_array($data['opening_balance_type'] ?? null, ['debit', 'credit'], true)
            ? $data['opening_balance_type']
            : 'debit';

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            SafeDeleteActions::deactivateAction()
                ->authorize(fn (): bool => DealerResource::canEdit($this->getRecord())),
            SafeDeleteActions::deleteAction()
                ->authorize(fn (): bool => DealerResource::canDelete($this->getRecord())),
            ForceDeleteAction::make()
                ->authorize(fn (): bool => auth()->user()?->can('forceDelete', $this->getRecord()) ?? false)
                ->before(function ($action): void {
                    $assessment = app(\App\Services\SafeDelete\SafeDeleteGuard::class)->assess($this->getRecord());
                    if ($assessment->blocked()) {
                        SafeDeleteActions::notifyBlocked($assessment);
                        $action->cancel();
                    }
                }),
            RestoreAction::make()
                ->authorize(fn (): bool => auth()->user()?->can('restore', $this->getRecord()) ?? false),
        ];
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->submit(null)
            ->action('save')
            ->requiresConfirmation(fn (): bool => $this->openingBalanceWillChange())
            ->modalHeading('Change Opening Balance?')
            ->modalDescription('Changing Opening Balance will change the dealer\'s complete ledger and current outstanding.')
            ->modalSubmitActionLabel('Save changes');
    }

    private function openingBalanceWillChange(): bool
    {
        $record = $this->getRecord();
        $data = $this->data ?? [];

        $newAmount = round((float) ($data['opening_balance'] ?? 0), 2);
        $oldAmount = round((float) $record->opening_balance, 2);

        $newType = strtolower((string) ($data['opening_balance_type'] ?? 'debit'));
        $oldType = strtolower((string) ($record->opening_balance_type ?? 'debit'));

        return $newAmount !== $oldAmount
            || $newType !== $oldType
            || $this->normalizeDate($data['opening_balance_date'] ?? null) !== $this->normalizeDate($record->opening_balance_date);
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return substr((string) $value, 0, 10);
    }
}
