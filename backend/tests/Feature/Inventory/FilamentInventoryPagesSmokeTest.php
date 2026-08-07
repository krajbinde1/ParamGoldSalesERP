<?php

use App\Enums\UserRole;
use App\Filament\Pages\FinishedGoodsImport;
use App\Filament\Pages\InventoryDashboard;
use App\Filament\Pages\InventoryReports;
use App\Filament\Pages\PackagingMaterialImport;
use App\Filament\Pages\RawMaterialImport;
use App\Filament\Pages\SemiFinishedMaterialImport;
use App\Filament\Resources\Boms\Pages\ListBoms;
use App\Filament\Resources\PackagingMaterialInwards\Pages\ListPackagingMaterialInwards;
use App\Filament\Resources\PackagingMaterials\Pages\ListPackagingMaterials;
use App\Filament\Resources\ProductionBatches\Pages\ListProductionBatches;
use App\Filament\Resources\RawMaterialInwards\Pages\ListRawMaterialInwards;
use App\Filament\Resources\RawMaterials\Pages\ListRawMaterials;
use App\Filament\Resources\SemiFinishedMaterials\Pages\ListSemiFinishedMaterials;
use App\Filament\Resources\StockAdjustments\Pages\ListStockAdjustments;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->director = User::query()->create([
        'name' => 'Inventory Nav Director',
        'email' => 'inv.nav.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Director',
    ]);
});

it('boots Inventory & Manufacturing menu pages without error', function (string $component): void {
    Livewire::actingAs($this->director)
        ->test($component)
        ->assertSuccessful();
})->with([
    'raw materials' => ListRawMaterials::class,
    'packaging materials' => ListPackagingMaterials::class,
    'semi finished' => ListSemiFinishedMaterials::class,
    'raw material inwards' => ListRawMaterialInwards::class,
    'packaging material inwards' => ListPackagingMaterialInwards::class,
    'boms' => ListBoms::class,
    'production batches' => ListProductionBatches::class,
    'stock adjustments' => ListStockAdjustments::class,
    'inventory dashboard' => InventoryDashboard::class,
    'inventory reports' => InventoryReports::class,
    'raw material import' => RawMaterialImport::class,
    'packaging material import' => PackagingMaterialImport::class,
    'semi finished material import' => SemiFinishedMaterialImport::class,
    'finished goods import' => FinishedGoodsImport::class,
]);

it('keeps packaging materials list under a tight query budget', function (): void {
    $queries = 0;

    \DB::listen(function () use (&$queries): void {
        $queries++;
    });

    Livewire::actingAs($this->director)
        ->test(ListPackagingMaterials::class)
        ->assertSuccessful();

    // Master list should not scan ledgers / inwards / stock history.
    expect($queries)->toBeLessThan(25);
});

it('keeps raw material inward list under a tight query budget', function (): void {
    $queries = 0;

    \DB::listen(function () use (&$queries): void {
        $queries++;
    });

    Livewire::actingAs($this->director)
        ->test(ListRawMaterialInwards::class)
        ->assertSuccessful();

    // Must not call hasSubsequentStockTransactions() per row (ledger scans).
    expect($queries)->toBeLessThan(40);
});
