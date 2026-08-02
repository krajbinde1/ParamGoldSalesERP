<?php

namespace App\Filament\Resources\RawMaterialInwards\Schemas;

use App\Filament\Support\MaterialInwardFormLayout;
use App\Models\RawMaterial;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class RawMaterialInwardForm
{
    public static function configure(Schema $schema, bool $forEdit = false): Schema
    {
        $materialOptions = RawMaterial::query()
            ->where('status', true)
            ->orderBy('material_name')
            ->get()
            ->mapWithKeys(fn (RawMaterial $m) => [
                $m->id => "{$m->material_code} — {$m->material_name}",
            ])
            ->all();

        $components = MaterialInwardFormLayout::schema(
            title: 'Inward Header',
            attachmentDirectory: 'raw-material-inwards',
            materialField: 'raw_material_id',
            materialLabel: 'Raw Material',
            materialOptions: $materialOptions,
            hydrateMaterial: function ($state, Set $set, Get $get): void {
                $material = RawMaterial::query()->find($state);
                MaterialInwardFormLayout::hydrateFromMaterial(
                    $set,
                    $material?->current_stock !== null ? (float) $material->current_stock : null,
                    $material?->unit,
                    $material?->purchase_rate !== null ? (float) $material->purchase_rate : null,
                    $material?->average_rate !== null ? (float) $material->average_rate : null,
                );
            },
        );

        if ($forEdit) {
            array_unshift($components, Section::make('Record Info')
                ->extraAttributes(['class' => 'paramgold-inward-compact'])
                ->schema([
                    Grid::make([
                        'default' => 1,
                        'md' => 2,
                        'lg' => 4,
                    ])->schema([
                        TextInput::make('inward_number')
                            ->label('Inward No.')
                            ->readOnly()
                            ->dehydrated(false),
                        TextInput::make('created_by_display')
                            ->label('Created By')
                            ->readOnly()
                            ->dehydrated(false),
                        TextInput::make('created_at_display')
                            ->label('Created At')
                            ->readOnly()
                            ->dehydrated(false),
                        TextInput::make('posted_at_display')
                            ->label('Posted At / Ledger Ref')
                            ->readOnly()
                            ->dehydrated(false)
                            ->helperText('Ledger rows are reversed then reposted; inward number is unchanged.'),
                    ]),
                ]));
        }

        return $schema
            ->columns(1)
            ->components($components);
    }
}
