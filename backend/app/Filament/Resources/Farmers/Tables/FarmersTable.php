<?php

namespace App\Filament\Resources\Farmers\Tables;

use App\Filament\Support\EmployeeSelect;
use App\Models\Crop;
use App\Models\Farmer;
use App\Models\MaharashtraDistrict;
use App\Models\MaharashtraTaluka;
use App\Models\Product;
use App\Support\MaharashtraGeography;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FarmersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('last_activity_date', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label('Farmer Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('mobile')
                    ->label('Mobile Number')
                    ->searchable(),
                TextColumn::make('district.name')
                    ->label('District')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('taluka.name')
                    ->label('Taluka')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('village')
                    ->searchable(),
                TextColumn::make('latestActivity.crop.name')
                    ->label('Last Crop')
                    ->placeholder('—'),
                TextColumn::make('last_activity_date')
                    ->label('Last Activity Date')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('createdByEmployee.full_name')
                    ->label('Employee Name')
                    ->formatStateUsing(fn (Farmer $record): string => $record->createdByEmployee?->displayLabel() ?? '—'),
                TextColumn::make('field_activities_count')
                    ->label('Total Activities')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label('State')
                    ->options([MaharashtraGeography::STATE_NAME => MaharashtraGeography::STATE_NAME])
                    ->query(fn (Builder $query): Builder => $query),
                SelectFilter::make('district_id')
                    ->label('District')
                    ->options(fn (): array => MaharashtraDistrict::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload(),
                SelectFilter::make('taluka_id')
                    ->label('Taluka')
                    ->options(fn (): array => MaharashtraTaluka::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                Filter::make('village')
                    ->form([
                        TextInput::make('value')->label('Village'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (! filled($data['value'] ?? null)) {
                            return $query;
                        }

                        return $query->where('village', 'like', '%'.$data['value'].'%');
                    }),
                SelectFilter::make('created_by_employee_id')
                    ->label('Employee')
                    ->relationship('createdByEmployee', 'full_name')
                    ->tap(fn (SelectFilter $filter) => EmployeeSelect::applyRelationshipFilter($filter))
                    ->searchable()
                    ->preload(),
                SelectFilter::make('crop_id')
                    ->label('Crop')
                    ->options(fn (): array => Crop::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(function (Builder $query, array $data): Builder {
                        if (! filled($data['value'] ?? null)) {
                            return $query;
                        }

                        return $query->whereHas(
                            'fieldActivities',
                            fn (Builder $inner) => $inner->where('crop_id', $data['value']),
                        );
                    }),
                SelectFilter::make('product_id')
                    ->label('Recommended Product')
                    ->options(fn (): array => Product::query()->orderBy('product_name')->pluck('product_name', 'id')->all())
                    ->searchable()
                    ->query(function (Builder $query, array $data): Builder {
                        if (! filled($data['value'] ?? null)) {
                            return $query;
                        }

                        return $query->whereHas(
                            'fieldActivities.recommendations',
                            fn (Builder $inner) => $inner->where('product_id', $data['value']),
                        );
                    }),
                Filter::make('activity_dates')
                    ->label('Date Range')
                    ->form([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('To'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                filled($data['from'] ?? null),
                                fn (Builder $inner): Builder => $inner->whereDate('last_activity_date', '>=', $data['from']),
                            )
                            ->when(
                                filled($data['until'] ?? null),
                                fn (Builder $inner): Builder => $inner->whereDate('last_activity_date', '<=', $data['until']),
                            );
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
