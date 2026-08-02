<?php

namespace App\Filament\Resources\PackagingMaterialInwards\Schemas;

use App\Filament\Support\MaterialInwardFormLayout;
use App\Models\PackagingMaterial;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class PackagingMaterialInwardForm
{
    public static function configure(Schema $schema): Schema
    {
        $materialOptions = PackagingMaterial::query()
            ->where('status', true)
            ->orderBy('packaging_name')
            ->get()
            ->mapWithKeys(fn (PackagingMaterial $m) => [
                $m->id => "{$m->packaging_code} — {$m->packaging_name}",
            ])
            ->all();

        return $schema
            ->columns(1)
            ->components(MaterialInwardFormLayout::schema(
                title: 'Inward Header',
                attachmentDirectory: 'packaging-material-inwards',
                materialField: 'packaging_material_id',
                materialLabel: 'Packaging Material',
                materialOptions: $materialOptions,
                hydrateMaterial: function ($state, Set $set, Get $get): void {
                    $material = PackagingMaterial::query()->find($state);
                    MaterialInwardFormLayout::hydrateFromMaterial(
                        $set,
                        $material?->current_stock !== null ? (float) $material->current_stock : null,
                        $material?->unit,
                        $material?->purchase_rate !== null ? (float) $material->purchase_rate : null,
                        $material?->average_rate !== null ? (float) $material->average_rate : null,
                    );
                },
            ));
    }
}
