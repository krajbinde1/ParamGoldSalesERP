<?php

namespace App\Filament\Resources\TaDaClaims\Schemas;

use App\Models\TaDaClaim;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TaDaClaimInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('TA/DA claim details')->columns(3)->schema([
                    TextEntry::make('employee.full_name')->label('Employee'),
                    TextEntry::make('claim_date')->label('Claim Date')->date('d M Y'),
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => TaDaClaim::statusLabel($state))
                        ->color(fn (string $state): string => match ($state) {
                            TaDaClaim::STATUS_APPROVED => 'success',
                            TaDaClaim::STATUS_PAID => 'info',
                            TaDaClaim::STATUS_REJECTED => 'danger',
                            default => 'warning',
                        }),
                    TextEntry::make('from_location')->label('From Location'),
                    TextEntry::make('to_location')->label('To Location'),
                    TextEntry::make('travel_km')->label('Travel KM')->numeric(decimalPlaces: 2),
                    TextEntry::make('per_km_rate')->label('Per KM Rate')->money('INR'),
                    TextEntry::make('travel_amount')->label('Travel Amount')->money('INR'),
                    TextEntry::make('da_amount')->label('DA Amount')->money('INR'),
                    TextEntry::make('other_expense')->label('Other Amount')->money('INR'),
                    TextEntry::make('total_amount')->label('Total Claim Amount')->money('INR'),
                    TextEntry::make('employee_remarks')
                        ->label('Employee Remarks')
                        ->placeholder('-')
                        ->columnSpanFull(),
                    TextEntry::make('admin_remark')
                        ->label('Admin Remark')
                        ->placeholder('-')
                        ->visible(fn (TaDaClaim $record): bool => filled($record->admin_remark))
                        ->columnSpanFull(),
                    ImageEntry::make('bill_photo_path')
                        ->label('Bill Photo')
                        ->getStateUsing(fn (TaDaClaim $record): ?string => $record->billPhotoUrl())
                        ->url(fn (TaDaClaim $record): ?string => $record->billPhotoUrl())
                        ->openUrlInNewTab()
                        ->imageHeight(240)
                        ->visible(fn (TaDaClaim $record): bool => filled($record->bill_photo_path))
                        ->columnSpanFull(),
                ]),
            ]);
    }
}
