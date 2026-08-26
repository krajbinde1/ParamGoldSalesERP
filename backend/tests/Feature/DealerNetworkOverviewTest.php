<?php

use App\Enums\UserRole;
use App\Filament\Pages\DealerNetwork;
use App\Filament\Resources\Dealers\DealerResource;
use App\Filament\Resources\Dealers\Pages\ListDealers;
use App\Services\Dealers\DealerNetworkOverviewService;
use Livewire\Livewire;

it('aggregates dealer network counts by district and taluka without using erp opening data', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9811100201');
    $otherEmployee = ledgerEmployee(UserRole::Employee, '9811100202');
    $admin = tallyImportAdmin();

    ledgerDealer($employee, [
        'firm_name' => 'Jalna Alpha Traders',
        'state' => 'Maharashtra',
        'district' => 'Jalna',
        'taluka' => 'Partur',
        'village' => 'Partur Town',
        'dealer_type' => 'Retailer',
    ]);
    ledgerDealer($employee, [
        'firm_name' => 'Jalna Beta Traders',
        'state' => 'Maharashtra',
        'district' => 'Jalna',
        'taluka' => 'Ambad',
        'village' => 'Ambad Town',
        'dealer_type' => 'Distributor',
    ]);
    ledgerDealer($otherEmployee, [
        'firm_name' => 'Beed Gamma Traders',
        'state' => 'Maharashtra',
        'district' => 'Beed',
        'taluka' => 'Georai',
        'village' => 'Georai Town',
        'dealer_type' => 'Retailer',
    ]);
    ledgerDealer($employee, [
        'firm_name' => 'Sambhajinagar Delta',
        'state' => 'Maharashtra',
        'district' => 'Chhatrapati Sambhajinagar',
        'taluka' => 'Paithan',
        'village' => 'Paithan Town',
        'dealer_type' => 'Wholesaler',
        'latitude' => 19.8765,
        'longitude' => 75.3432,
    ]);

    $overview = app(DealerNetworkOverviewService::class)->overview($admin, []);

    expect($overview['summary']['total_dealers'])->toBe(4)
        ->and($overview['summary']['total_districts'])->toBe(3)
        ->and($overview['summary']['total_talukas'])->toBe(4)
        ->and($overview['summary']['total_villages'])->toBe(4)
        ->and(collect($overview['districts'])->pluck('count', 'name')->all())->toMatchArray([
            'Jalna' => 2,
            'Beed' => 1,
            'Chhatrapati Sambhajinagar' => 1,
        ])
        ->and(collect($overview['areas'])->firstWhere('name', 'Jalna'))->toMatchArray([
            'name' => 'Jalna',
            'dealer_count' => 2,
            'taluka_count' => 2,
            'village_count' => 2,
        ])
        ->and($overview['has_mappable_dealers'])->toBeTrue()
        ->and($overview['markers'])->toHaveCount(1)
        ->and($overview['markers'][0]['firm_name'])->toBe('Sambhajinagar Delta');

    $jalnaOnly = app(DealerNetworkOverviewService::class)->overview($admin, ['district' => 'Jalna']);
    expect($jalnaOnly['summary']['total_dealers'])->toBe(2)
        ->and(collect($jalnaOnly['talukas'])->pluck('name')->all())->toEqualCanonicalizing(['Partur', 'Ambad'])
        ->and($jalnaOnly['talukas_are_top_overall'])->toBeFalse();
});

it('does not show dealer network overview sections on the dealers list', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9811100203');
    $admin = tallyImportAdmin();
    $dealer = ledgerDealer($employee, [
        'firm_name' => 'Plain List Dealer',
        'state' => 'Maharashtra',
        'district' => 'Jalna',
        'taluka' => 'Partur',
        'village' => 'Ashti',
        'dealer_type' => 'Retailer',
    ]);

    Livewire::actingAs($admin)
        ->test(ListDealers::class)
        ->assertSuccessful()
        ->assertDontSee('Dealer Network Overview')
        ->assertDontSee('Area Network')
        ->assertDontSee('District-wise Dealer Network')
        ->assertCanSeeTableRecords([$dealer]);
});

it('does not show a map toggle when dealers have no coordinates', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9811100204');
    $admin = tallyImportAdmin();
    ledgerDealer($employee, [
        'firm_name' => 'No Gps Dealer',
        'state' => 'Maharashtra',
        'district' => 'Jalna',
        'taluka' => 'Partur',
        'village' => 'Partur',
        'latitude' => null,
        'longitude' => null,
    ]);

    Livewire::actingAs($admin)
        ->test(DealerNetwork::class)
        ->assertSuccessful()
        ->assertDontSee('Map View');
});

it('registers dealer network under sales operations for the same roles as dealers', function (): void {
    $admin = tallyImportAdmin();

    $this->actingAs($admin);

    expect(DealerNetwork::canAccess())->toBeTrue()
        ->and(DealerNetwork::shouldRegisterNavigation())->toBeTrue()
        ->and(DealerNetwork::getNavigationGroup())->toBe('Sales Operations')
        ->and(DealerNetwork::getNavigationLabel())->toBe('Dealer Network')
        ->and(DealerResource::canAccess())->toBeTrue();

    expect(DealerNetwork::getUrl())->toContain('dealer-network');
});

it('opens the existing dealer network overview from the sales operations menu page', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9811100205');
    $admin = tallyImportAdmin();
    $jalnaPartur = ledgerDealer($employee, [
        'firm_name' => 'Sidebar Jalna Partur Dealer',
        'state' => 'Maharashtra',
        'district' => 'Jalna',
        'taluka' => 'Partur',
        'village' => 'Ashti',
        'dealer_type' => 'Retailer',
    ]);
    $jalnaAmbad = ledgerDealer($employee, [
        'firm_name' => 'Sidebar Jalna Ambad Dealer',
        'state' => 'Maharashtra',
        'district' => 'Jalna',
        'taluka' => 'Ambad',
        'village' => 'Ambad',
        'dealer_type' => 'Retailer',
    ]);
    $beed = ledgerDealer($employee, [
        'firm_name' => 'Sidebar Beed Dealer',
        'state' => 'Maharashtra',
        'district' => 'Beed',
        'taluka' => 'Georai',
        'village' => 'Georai',
        'dealer_type' => 'Retailer',
    ]);

    Livewire::actingAs($admin)
        ->test(DealerNetwork::class)
        ->assertSuccessful()
        ->assertSee('Dealer Network Overview')
        ->assertSee('Total Dealers')
        ->assertSee('Districts Covered')
        ->assertSee('Talukas Covered')
        ->assertSee('Villages Covered')
        ->assertSee('District-wise Dealer Network')
        ->assertSee('Taluka-wise Distribution')
        ->assertSee('Area Network')
        ->assertCanSeeTableRecords([$jalnaPartur, $jalnaAmbad, $beed])
        ->call('selectNetworkDistrict', 'Jalna')
        ->assertSet('networkDistrict', 'Jalna')
        ->assertCanSeeTableRecords([$jalnaPartur, $jalnaAmbad])
        ->assertCanNotSeeTableRecords([$beed])
        ->call('selectNetworkTaluka', 'Partur', 'Jalna')
        ->assertSet('networkTaluka', 'Partur')
        ->assertCanSeeTableRecords([$jalnaPartur])
        ->assertCanNotSeeTableRecords([$jalnaAmbad, $beed]);
});
