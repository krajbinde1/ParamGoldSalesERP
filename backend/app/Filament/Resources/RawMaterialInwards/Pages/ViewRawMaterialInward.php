<?php

namespace App\Filament\Resources\RawMaterialInwards\Pages;

use App\Filament\Resources\RawMaterialInwards\RawMaterialInwardResource;
use App\Models\RawMaterialBatch;
use App\Models\RawMaterialInward;
use App\Models\RawMaterialInwardItem;
use App\Services\Inventory\RawMaterialInwardService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

class ViewRawMaterialInward extends ViewRecord
{
    protected static string $resource = RawMaterialInwardResource::class;

    public function form(Schema $schema): Schema
    {
        // View uses infolist only; skip create-form schema on this page.
        return $schema->components([]);
    }

    protected function getHeaderActions(): array
    {
        /** @var RawMaterialInward $record */
        $record = $this->getRecord();

        return [
            Action::make('cancel')
                ->label('Cancel Inward')
                ->color('danger')
                ->requiresConfirmation()
                ->form([
                    Textarea::make('cancellation_reason')->required(),
                ])
                ->visible(fn (): bool => auth()->user()?->can('cancel', $record) ?? false)
                ->action(function (array $data) use ($record): void {
                    $this->runService(
                        fn () => app(RawMaterialInwardService::class)->cancel($record, auth()->user(), $data['cancellation_reason'] ?? null),
                        'Inward cancelled',
                    );
                }),
            Action::make('return')
                ->label('Inward Return')
                ->color('danger')
                ->visible(fn (): bool => auth()->user()?->can('returnInward', $record) ?? false)
                ->form([
                    Select::make('raw_material_inward_item_id')
                        ->label('Inward Item')
                        ->options(fn (): array => $record->items->mapWithKeys(
                            fn (RawMaterialInwardItem $item) => [
                                $item->id => "{$item->material_code} — Acc: {$item->accepted_quantity} {$item->unit}",
                            ],
                        )->all())
                        ->required()
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set): void {
                            $item = RawMaterialInwardItem::query()->find($state);
                            $set('raw_material_id', $item?->raw_material_id);
                            $batch = RawMaterialBatch::query()->where('inward_item_id', $state)->first();
                            $set('raw_material_batch_id', $batch?->id);
                        }),
                    TextInput::make('raw_material_id')->hidden()->dehydrated(),
                    Select::make('raw_material_batch_id')
                        ->label('Batch')
                        ->options(fn (): array => RawMaterialBatch::query()
                            ->where('inward_id', $record->id)
                            ->get()
                            ->mapWithKeys(fn (RawMaterialBatch $batch) => [
                                $batch->id => "{$batch->internal_batch_number} (Avail: {$batch->available_quantity})",
                            ])
                            ->all())
                        ->searchable(),
                    DatePicker::make('return_date')
                        ->native(false)
                        ->required()
                        ->default(now('Asia/Kolkata')->toDateString()),
                    TextInput::make('return_quantity')
                        ->numeric()
                        ->minValue(0.001)
                        ->required(),
                    TextInput::make('reason')->required()->maxLength(255),
                    TextInput::make('supplier_credit_note_number')->label('Credit Note No.')->maxLength(100),
                    Textarea::make('remarks')->rows(2),
                ])
                ->action(function (array $data) use ($record): void {
                    $this->runService(
                        fn () => app(RawMaterialInwardService::class)->createAndPostReturn([
                            ...$data,
                            'raw_material_inward_id' => $record->id,
                        ], auth()->user()),
                        'Inward return posted',
                    );
                }),
        ];
    }

    private function runService(callable $callback, string $successMessage): void
    {
        try {
            $callback();
            Notification::make()->title($successMessage)->success()->send();
            $this->refreshFormData(['status', 'posted_at']);
            $this->dispatch('$refresh');
        } catch (ValidationException $e) {
            Notification::make()
                ->title(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                ->danger()
                ->send();
        }
    }
}
