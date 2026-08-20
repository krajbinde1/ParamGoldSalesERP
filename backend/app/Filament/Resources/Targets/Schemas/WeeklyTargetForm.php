<?php

namespace App\Filament\Resources\Targets\Schemas;

use App\Models\WeeklyTarget;
use App\Filament\Support\EmployeeSelect;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class WeeklyTargetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Weekly target')->columns(2)->schema([
                    Select::make('employee_id')
                        ->label('Employee')
                        ->relationship('employee', 'full_name', fn (Builder $query) => $query->where('status', true))
                        ->searchable()
                        ->preload()
                        ->required()
                        ->tap(fn (Select $select) => EmployeeSelect::applyRelationshipSelect($select)),
                    Select::make('status')
                        ->options(WeeklyTarget::STATUS_LABELS)
                        ->default('active')
                        ->required(),
                    DatePicker::make('week_start_date')
                        ->label('From Date')
                        ->native(false)
                        ->required()
                        ->live()
                        ->rules(fn (Get $get, ?WeeklyTarget $record): array => [
                            Rule::unique('weekly_targets', 'week_start_date')
                                ->where(fn ($query) => $query->where('employee_id', $get('employee_id')))
                                ->ignore($record),
                        ]),
                    DatePicker::make('week_end_date')
                        ->label('To Date')
                        ->native(false)
                        ->required()
                        ->afterOrEqual('week_start_date'),
                    Placeholder::make('period_month')
                        ->label('Period / Month')
                        ->content(function (Get $get): string {
                            $start = $get('week_start_date');
                            if (blank($start)) {
                                return '—';
                            }

                            return Carbon::parse($start)->format('F Y');
                        }),
                    TextInput::make('sales_target')
                        ->label('Weekly Sales Target')
                        ->prefix('₹')
                        ->numeric()
                        ->minValue(0)
                        ->required(),
                    TextInput::make('collection_target')
                        ->label('Weekly Collection Target')
                        ->prefix('₹')
                        ->numeric()
                        ->minValue(0)
                        ->required(),
                    TextInput::make('field_activity_target')
                        ->label('Field Activity Target')
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->default(0)
                        ->required(),
                    Textarea::make('remark')
                        ->label('Remark')
                        ->rows(3)
                        ->maxLength(2000)
                        ->columnSpanFull(),
                ]),
            ]);
    }
}
