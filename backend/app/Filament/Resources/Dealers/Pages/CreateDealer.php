<?php

namespace App\Filament\Resources\Dealers\Pages;

use App\Filament\Resources\Dealers\DealerResource;
use App\Support\MaharashtraGeography;
use Filament\Resources\Pages\CreateRecord;

class CreateDealer extends CreateRecord
{
    protected static string $resource = DealerResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = MaharashtraGeography::canonicalizeLocationFields($data);
        $data['opening_balance_type'] = in_array($data['opening_balance_type'] ?? null, ['debit', 'credit'], true)
            ? $data['opening_balance_type']
            : 'debit';

        return $data;
    }
}
