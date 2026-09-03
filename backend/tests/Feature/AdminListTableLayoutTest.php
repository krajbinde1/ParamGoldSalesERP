<?php

use App\Enums\UserRole;
use App\Filament\Resources\Attendances\Pages\ListAttendances;
use App\Filament\Resources\Collections\Pages\ListCollections;
use App\Filament\Resources\Dealers\Pages\ListDealers;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\FieldActivities\Pages\ListFieldActivities;
use App\Filament\Resources\FinishedProducts\Pages\ListFinishedProducts;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\PackagingMaterials\Pages\ListPackagingMaterials;
use App\Filament\Resources\PaymentRequests\Pages\ListPaymentRequests;
use App\Filament\Resources\RawMaterials\Pages\ListRawMaterials;
use App\Filament\Resources\SemiFinishedMaterials\Pages\ListSemiFinishedMaterials;
use App\Filament\Resources\Targets\Pages\ListWeeklyTargets;
use App\Models\User;
use Livewire\Livewire;

function adminListLayoutUser(): User
{
    return User::query()->create([
        'name' => 'Admin List Layout',
        'email' => 'admin.list.layout.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Admin',
    ]);
}

it('applies the dealer list table layout as a shared admin theme', function (): void {
    $css = view('filament.partials.paramgold-admin-theme')->render();

    expect($css)
        ->toContain('Admin list / table standard - Dealer List is the reference.')
        ->toContain('.paramgold-admin-shell .fi-ta .fi-ta-search-field')
        ->toContain('.paramgold-admin-shell .fi-ta .fi-ta-content')
        ->toContain('.paramgold-admin-shell .fi-ta .fi-pagination')
        ->toContain('.paramgold-admin-shell .fi-ta .fi-ta-cell:has(.fi-ta-actions)')
        ->not->toContain('.pg-payment-requests-page .fi-ta-table');
});

it('keeps core admin list pages rendering with the shared table markup', function (): void {
    $admin = adminListLayoutUser();

    foreach ([
        ListDealers::class,
        ListOrders::class,
        ListCollections::class,
        ListPaymentRequests::class,
        ListWeeklyTargets::class,
        ListEmployees::class,
        ListRawMaterials::class,
        ListPackagingMaterials::class,
        ListFinishedProducts::class,
        ListSemiFinishedMaterials::class,
        ListFieldActivities::class,
        ListAttendances::class,
    ] as $page) {
        Livewire::actingAs($admin)
            ->test($page)
            ->assertSuccessful()
            ->assertSeeHtml('fi-ta');
    }
});
