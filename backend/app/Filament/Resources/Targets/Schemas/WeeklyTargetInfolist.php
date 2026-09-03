<?php

namespace App\Filament\Resources\Targets\Schemas;

use App\Models\WeeklyTarget;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WeeklyTargetInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Target')->columns(2)->schema([
                    TextEntry::make('employee.full_name')->label('Employee'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('target_type')
                        ->label('Type')
                        ->badge()
                        ->state(fn (WeeklyTarget $record): string => $record->isGeneratedFromMonthly() ? 'Monthly split' : 'Weekly')
                        ->color(fn (WeeklyTarget $record): string => $record->isGeneratedFromMonthly() ? 'info' : 'gray'),
                    TextEntry::make('monthly_source')
                        ->label('Monthly Target')
                        ->visible(fn (WeeklyTarget $record): bool => $record->isGeneratedFromMonthly())
                        ->state(function (WeeklyTarget $record): string {
                            $monthly = $record->monthlyTarget;
                            if ($monthly === null) {
                                return '—';
                            }

                            return $monthly->monthLabel()
                                .' · Sales ₹'.number_format((float) $monthly->sales_target, 2)
                                .' · Collection ₹'.number_format((float) $monthly->collection_target, 2)
                                .' · Field '.(int) $monthly->field_activity_target;
                        })
                        ->columnSpanFull(),
                    TextEntry::make('week_start_date')->label('From Date')->date('d M Y'),
                    TextEntry::make('week_end_date')->label('To Date')->date('d M Y'),
                    TextEntry::make('period_month')
                        ->label('Period / Month')
                        ->state(function (WeeklyTarget $record): string {
                            if ($record->monthlyTarget !== null) {
                                return $record->monthlyTarget->monthLabel();
                            }

                            return $record->week_start_date->format('F Y');
                        }),
                    TextEntry::make('sales_target')->label('Sales Target')->money('INR'),
                    TextEntry::make('collection_target')->label('Collection Target')->money('INR'),
                    TextEntry::make('field_activity_target')->label('Field Activity Target'),
                    TextEntry::make('remark')->label('Remark')->placeholder('—')->columnSpanFull(),
                ]),
            ]);
    }
}
