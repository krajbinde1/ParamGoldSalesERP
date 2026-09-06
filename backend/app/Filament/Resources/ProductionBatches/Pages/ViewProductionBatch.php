<?php

namespace App\Filament\Resources\ProductionBatches\Pages;

use App\Enums\ProductionBatchStatus;
use App\Filament\Resources\ProductionBatches\ProductionBatchResource;
use App\Models\ProductionBatch;
use App\Services\Inventory\BatchReversalService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class ViewProductionBatch extends ViewRecord
{
    protected static string $resource = ProductionBatchResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->getRecord()->loadMissing([
            'product',
            'semiFinished',
            'bom',
            'supervisor',
            'consumptions',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtraBodyAttributes(): array
    {
        return [
            'class' => 'pg-production-batch-view-page',
        ];
    }

    public static function printSheetUrl(ProductionBatch $batch): string
    {
        return route('filament.admin.production-batches.print-sheet', [
            'productionBatch' => $batch,
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('printBatchSheet')
                ->label('Print Batch Sheet')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->visible(fn (): bool => in_array(
                    $this->getRecord()->status,
                    [ProductionBatchStatus::Completed, ProductionBatchStatus::Reversed],
                    true,
                ))
                ->url(fn (): string => static::printSheetUrl($this->getRecord()))
                ->openUrlInNewTab(),
            Action::make('reverseBatch')
                ->label('Reverse Batch')
                ->color('danger')
                ->icon('heroicon-o-arrow-uturn-left')
                ->visible(fn (): bool => auth()->user()?->can('reverse', $this->getRecord()) ?? false)
                ->requiresConfirmation()
                ->modalHeading('Reverse completed production batch')
                ->modalDescription('This will restore consumed raw/packaging materials, deduct finished goods stock, and mark the batch as Reversed. The original batch record will be retained.')
                ->form([
                    Textarea::make('reversal_reason')
                        ->label('Reversal Reason')
                        ->required()
                        ->rows(3),
                ])
                ->action(function (array $data): void {
                    /** @var ProductionBatch $batch */
                    $batch = $this->getRecord();

                    try {
                        app(BatchReversalService::class)->reverse(
                            $batch,
                            (string) $data['reversal_reason'],
                            auth()->user(),
                        );

                        Notification::make()
                            ->success()
                            ->title('Batch reversed')
                            ->body("Batch {$batch->batch_number} has been reversed and stock has been restored.")
                            ->send();

                        $this->refreshFormData(['status', 'reversal_reason', 'reversed_at']);
                        $this->redirect(ProductionBatchResource::getUrl('view', ['record' => $batch->fresh()]));
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->danger()
                            ->title('Unable to reverse batch')
                            ->body(collect($exception->errors())->flatten()->first() ?? 'Reversal failed.')
                            ->send();
                    }
                }),
        ];
    }
}
