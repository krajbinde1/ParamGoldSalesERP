<?php

namespace App\Filament\Resources\Products\Tables;

use App\Filament\Actions\SafeDeleteActions;
use App\Filament\Resources\Boms\BomResource;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Models\Product;
use App\Services\SafeDelete\SafeDeleteGuard;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('activeBom'))
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->persistColumnSearchesInSession()
            ->persistSortInSession()
            ->columns([
                TextColumn::make('product_code')
                    ->label('Code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('product_name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('uom')
                    ->badge(),
                TextColumn::make('nos_per_case')
                    ->label('Nos/Case')
                    ->sortable(),
                TextColumn::make('active_packing_bom_status')
                    ->label(fn (): HtmlString => new HtmlString(
                        '<span class="inline-flex items-center gap-1.5">'
                        .'<span>BOM Status</span>'
                        .'<select wire:model.live="tableFilters.bom_status.value" class="fi-input fi-select-input text-xs" aria-label="Filter by BOM Status">'
                        .'<option value="">All</option>'
                        .'<option value="set">BOM Set</option>'
                        .'<option value="not_set">BOM Not Set</option>'
                        .'</select>'
                        .'</span>'
                    ))
                    ->badge()
                    ->state(fn (Product $record): string => $record->activeBom !== null
                        ? 'BOM Set'
                        : 'BOM Not Set')
                    ->color(fn (string $state): string => $state === 'BOM Set' ? 'success' : 'warning')
                    ->url(function (Product $record): ?string {
                        $bom = $record->activeBom;
                        if ($bom === null) {
                            return null;
                        }

                        $user = auth()->user();
                        if ($user === null || ! $user->can('view', $bom)) {
                            return null;
                        }

                        return BomResource::getUrl('view', ['record' => $bom]);
                    }),
                TextColumn::make('gst_percentage')
                    ->label('GST')
                    ->suffix('%')
                    ->sortable(),
                TextColumn::make('dealer_price')
                    ->money('INR')
                    ->sortable(),
                IconColumn::make('status')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('gst_percentage')
                    ->label('GST %')
                    ->options(ProductForm::gstOptions()),
                TernaryFilter::make('status')
                    ->label('Status')
                    ->placeholder('All')
                    ->trueLabel('Active')
                    ->falseLabel('Inactive')
                    ->default(true),
                SelectFilter::make('bom_status')
                    ->label('BOM Status')
                    ->options([
                        'set' => 'BOM Set',
                        'not_set' => 'BOM Not Set',
                    ])
                    ->placeholder('All')
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'set' => $query->whereHas('activeBom'),
                            'not_set' => $query->whereDoesntHave('activeBom'),
                            default => $query,
                        };
                    }),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->authorize(fn (Product $record): bool => auth()->user()?->can('update', $record) ?? false),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    SafeDeleteActions::deleteBulkAction()
                        ->authorize(fn (): bool => auth()->user()?->can('deleteAny', Product::class) ?? false),
                    ForceDeleteBulkAction::make()
                        ->authorize(fn (): bool => auth()->user()?->can('forceDeleteAny', Product::class) ?? false)
                        ->using(function (ForceDeleteBulkAction $action, Collection $records): void {
                            $guard = app(SafeDeleteGuard::class);
                            $records->each(function (Product $record) use ($action, $guard): void {
                                try {
                                    $guard->assertCanDelete($record);
                                    $record->forceDelete();
                                } catch (\Throwable $exception) {
                                    $action->reportBulkProcessingFailure();
                                    report($exception);
                                }
                            });
                        }),
                    RestoreBulkAction::make()
                        ->authorize(fn (): bool => auth()->user()?->can('restoreAny', Product::class) ?? false),
                ]),
            ]);
    }
}
