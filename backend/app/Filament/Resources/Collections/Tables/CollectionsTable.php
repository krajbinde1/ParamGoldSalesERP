<?php

namespace App\Filament\Resources\Collections\Tables;

use App\Filament\Resources\Collections\Actions\EditCollectionStatusAction;
use App\Filament\Support\TodayDateFilter;
use App\Models\Collection;
use App\Support\AttendanceCalendar;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CollectionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('receipt_no')->label('Receipt No.')->searchable()->sortable(),
                TextColumn::make('collection_date')->date()->sortable(),
                TextColumn::make('dealer.firm_name')->label('Dealer')->searchable(),
                TextColumn::make('salesEmployee.full_name')->label('Sales Employee')->placeholder('-')->searchable(),
                TextColumn::make('amount')->money('INR')->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Collection::statusLabels()[$state] ?? $state)
                    ->color(fn (string $state): string => Collection::statusColor($state))
                    ->sortable(),
                ImageColumn::make('photo_path')
                    ->label('Photo')
                    ->disk('public')
                    ->square()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(Collection::statusLabels()),
                TodayDateFilter::make('collection_date', 'Collection Date'),
                Filter::make('this_month_received')
                    ->label('This month received')
                    ->toggle()
                    ->query(function (Builder $query): Builder {
                        $now = AttendanceCalendar::now();

                        return $query
                            ->whereBetween('collection_date', [
                                $now->copy()->startOfMonth()->toDateString(),
                                $now->copy()->endOfMonth()->toDateString(),
                            ])
                            ->where('status', Collection::STATUS_RECEIVED);
                    }),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditCollectionStatusAction::make(),
                Action::make('markReceived')
                    ->label('Mark Received')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Collection $record): bool => $record->canTransitionTo(Collection::STATUS_RECEIVED))
                    ->action(fn (Collection $record) => $record->transitionTo(Collection::STATUS_RECEIVED)),
                Action::make('markNotReceived')
                    ->label('Mark Not Received')
                    ->color('danger')
                    ->visible(fn (Collection $record): bool => $record->canTransitionTo(Collection::STATUS_NOT_RECEIVED))
                    ->form([
                        Textarea::make('admin_remark')
                            ->label('Admin Remark')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(fn (Collection $record, array $data) => $record->transitionTo(
                        Collection::STATUS_NOT_RECEIVED,
                        ['admin_remark' => $data['admin_remark']],
                    )),
            ])
            ->toolbarActions([
                BulkActionGroup::make([]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
