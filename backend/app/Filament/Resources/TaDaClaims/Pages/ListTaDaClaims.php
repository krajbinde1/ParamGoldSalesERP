<?php

namespace App\Filament\Resources\TaDaClaims\Pages;

use App\Filament\Resources\TaDaClaims\TaDaClaimResource;
use App\Models\TaDaSetting;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListTaDaClaims extends ListRecords
{
    protected static string $resource = TaDaClaimResource::class;

    protected function getHeaderActions(): array
    {
        $activeRate = TaDaSetting::query()
            ->where('is_active', true)
            ->orderByDesc('id')
            ->value('per_km_rate');

        return [
            Action::make('perKmRate')
                ->label($activeRate === null
                    ? 'Set Per KM Rate'
                    : 'Per KM Rate: ₹'.number_format((float) $activeRate, 2))
                ->form([
                    TextInput::make('per_km_rate')
                        ->label('TA/DA Per KM Rate')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->default($activeRate),
                ])
                ->action(function (array $data): void {
                    TaDaSetting::query()->create([
                        'per_km_rate' => round((float) $data['per_km_rate'], 2),
                        'is_active' => true,
                    ]);

                    Notification::make()
                        ->title('TA/DA per KM rate updated.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
