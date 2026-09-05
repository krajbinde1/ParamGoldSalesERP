<?php

namespace App\Filament\Resources\CreditNotes\Pages;

use App\Filament\Concerns\RedirectsToPreviousPageAfterSave;
use App\Filament\Resources\CreditNotes\CreditNoteResource;
use Filament\Resources\Pages\EditRecord;

class EditCreditNote extends EditRecord
{
    use RedirectsToPreviousPageAfterSave;

    protected static string $resource = CreditNoteResource::class;

    public static function canAccess(array $parameters = []): bool
    {
        return false;
    }
}
