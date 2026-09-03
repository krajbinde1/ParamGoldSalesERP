<?php

namespace App\Filament\Resources\Targets\Schemas;

use App\Filament\Support\EmployeeSelect;
use App\Models\MonthlyTarget;
use App\Models\WeeklyTarget;
use App\Services\Targets\MonthlyTargetWeekSplitter;
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
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

class WeeklyTargetForm
{
    public static function configure(Schema $schema): Schema
    {
        $isMonthly = fn (Get $get): bool => $get('target_type') === MonthlyTarget::TYPE;
        $isWeekly = fn (Get $get): bool => $get('target_type') !== MonthlyTarget::TYPE;

        return $schema
            ->components([
                Section::make('Target')->columns(2)->schema([
                    Select::make('target_type')
                        ->label('Target Type')
                        ->options([
                            MonthlyTarget::WEEKLY_TYPE => 'Weekly',
                            MonthlyTarget::TYPE => 'Monthly',
                        ])
                        ->default(MonthlyTarget::WEEKLY_TYPE)
                        ->required()
                        ->live()
                        ->disabled(fn (?WeeklyTarget $record): bool => $record !== null)
                        ->dehydrated(),
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
                    DatePicker::make('month_start_date')
                        ->label('Month')
                        ->helperText('Weekly targets are created automatically from this month’s date ranges.')
                        ->native(false)
                        ->displayFormat('F Y')
                        ->default(now('Asia/Kolkata')->startOfMonth()->toDateString())
                        ->visible($isMonthly)
                        ->required($isMonthly),
                    DatePicker::make('week_start_date')
                        ->label('From Date')
                        ->native(false)
                        ->visible($isWeekly)
                        ->required($isWeekly)
                        ->live()
                        ->rules(fn (Get $get, ?WeeklyTarget $record): array => $get('target_type') === MonthlyTarget::TYPE
                            ? []
                            : [
                                Rule::unique('weekly_targets', 'week_start_date')
                                    ->where(fn ($query) => $query->where('employee_id', $get('employee_id')))
                                    ->ignore($record),
                            ]),
                    DatePicker::make('week_end_date')
                        ->label('To Date')
                        ->native(false)
                        ->visible($isWeekly)
                        ->required($isWeekly)
                        ->afterOrEqual('week_start_date'),
                    Placeholder::make('period_month')
                        ->label('Period / Month')
                        ->visible($isWeekly)
                        ->content(function (Get $get): string {
                            $start = $get('week_start_date');
                            if (blank($start)) {
                                return '—';
                            }

                            return Carbon::parse($start)->format('F Y');
                        }),
                    TextInput::make('sales_target')
                        ->label(fn (Get $get): string => $get('target_type') === MonthlyTarget::TYPE
                            ? 'Monthly Sales Target'
                            : 'Weekly Sales Target')
                        ->prefix('₹')
                        ->numeric()
                        ->minValue(0)
                        ->live(onBlur: true)
                        ->required(),
                    TextInput::make('collection_target')
                        ->label(fn (Get $get): string => $get('target_type') === MonthlyTarget::TYPE
                            ? 'Monthly Collection Target'
                            : 'Weekly Collection Target')
                        ->prefix('₹')
                        ->numeric()
                        ->minValue(0)
                        ->live(onBlur: true)
                        ->required(),
                    TextInput::make('field_activity_target')
                        ->label(fn (Get $get): string => $get('target_type') === MonthlyTarget::TYPE
                            ? 'Monthly Field Activity Target'
                            : 'Field Activity Target')
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->default(0)
                        ->live(onBlur: true)
                        ->required(),
                    Placeholder::make('weekly_split_preview')
                        ->label('Weekly split')
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => $get('target_type') === MonthlyTarget::TYPE
                            && filled($get('month_start_date')))
                        ->content(fn (Get $get): HtmlString => new HtmlString(self::weeklySplitPreview($get))),
                    Textarea::make('remark')
                        ->label('Remark')
                        ->rows(3)
                        ->maxLength(2000)
                        ->columnSpanFull(),
                ]),
            ]);
    }

    private static function weeklySplitPreview(Get $get): string
    {
        $splitter = app(MonthlyTargetWeekSplitter::class);
        $weeks = $splitter->weeksForMonth((string) $get('month_start_date'));
        $days = array_column($weeks, 'days');
        $sales = is_numeric($get('sales_target')) ? (float) $get('sales_target') : 0.0;
        $collection = is_numeric($get('collection_target')) ? (float) $get('collection_target') : 0.0;
        $field = is_numeric($get('field_activity_target')) ? (int) $get('field_activity_target') : 0;
        $salesShares = $splitter->allocateMoney($sales, $days);
        $collectionShares = $splitter->allocateMoney($collection, $days);
        $fieldShares = $splitter->allocateUnits($field, $days);

        $rows = '';
        foreach ($weeks as $index => $week) {
            $rows .= '<tr>'
                .'<td style="padding:4px 8px 4px 0;">'.$week['start']->format('d M').' – '.$week['end']->format('d M Y').'</td>'
                .'<td style="padding:4px 8px;">'.$week['days'].' day'.($week['days'] === 1 ? '' : 's').'</td>'
                .'<td style="padding:4px 8px;">₹'.number_format((float) $salesShares[$index], 2).'</td>'
                .'<td style="padding:4px 8px;">₹'.number_format((float) $collectionShares[$index], 2).'</td>'
                .'<td style="padding:4px 8px;">'.(int) $fieldShares[$index].'</td>'
                .'</tr>';
        }

        return '<table style="width:100%;font-size:13px;border-collapse:collapse;">'
            .'<thead><tr>'
            .'<th style="text-align:left;padding:4px 8px 4px 0;">Week</th>'
            .'<th style="text-align:left;padding:4px 8px;">Days</th>'
            .'<th style="text-align:left;padding:4px 8px;">Sales</th>'
            .'<th style="text-align:left;padding:4px 8px;">Collection</th>'
            .'<th style="text-align:left;padding:4px 8px;">Field</th>'
            .'</tr></thead><tbody>'.$rows.'</tbody></table>';
    }
}
