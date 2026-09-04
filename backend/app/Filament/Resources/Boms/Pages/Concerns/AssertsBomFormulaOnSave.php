<?php

namespace App\Filament\Resources\Boms\Pages\Concerns;

use App\Enums\BomStatus;
use App\Services\Inventory\BOMCalculationService;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

trait AssertsBomFormulaOnSave
{
    protected function assertBomFormulaFromFormState(): void
    {
        $state = $this->data;

        try {
            app(BOMCalculationService::class)->assertBomFormulaForSave(
                $state,
                $state['items'] ?? [],
                $state['status'] ?? BomStatus::Inactive->value,
            );
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();
            if (filled($message)) {
                Notification::make()
                    ->danger()
                    ->title($message)
                    ->persistent()
                    ->send();
            }

            throw ValidationException::withMessages(
                collect($exception->errors())
                    ->mapWithKeys(fn (array $messages, string $key): array => [
                        str_starts_with($key, 'data.') ? $key : 'data.'.$key => $messages,
                    ])
                    ->all()
            );
        }
    }
}
