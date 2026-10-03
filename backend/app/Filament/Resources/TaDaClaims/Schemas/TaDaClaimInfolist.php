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
                    TextEntry::make('employee.full_name')->label('Submitted By'),
                    TextEntry::make('submitter_role')
                        ->label('Role')
                        ->formatStateUsing(fn (TaDaClaim $record): string => $record->submitterRoleLabel()),
                    TextEntry::make('created_at')->label('Submitted At')->dateTime('d M Y • h:i A'),
                    TextEntry::make('claim_date')->label('Claim Date')->date('d M Y'),
                    TextEntry::make('status')
                        ->label('Approval Status')
                        ->badge()
                        ->formatStateUsing(fn (TaDaClaim $record): string => $record->displayStatusLabel())
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
                        ->label('Rejection Remark')
                        ->placeholder('-')
                        ->visible(fn (TaDaClaim $record): bool => filled($record->admin_remark))
                        ->columnSpanFull(),
                    TextEntry::make('approvedByUser.name')->label('Approved By')->placeholder('-'),
                    TextEntry::make('approver_role')->label('Approver Role')->placeholder('-'),
                    TextEntry::make('approved_at')->label('Approved At')->dateTime('d M Y • h:i A')->placeholder('-'),
                    TextEntry::make('rejectedByUser.name')->label('Rejected By')->placeholder('-'),
                    TextEntry::make('rejected_at')->label('Rejected At')->dateTime('d M Y • h:i A')->placeholder('-'),
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
