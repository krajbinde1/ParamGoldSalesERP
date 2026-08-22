<?php

use App\Support\MaharashtraGeography;
use App\Enums\UserRole;
use App\Models\User;

it('maps former Maharashtra district names without rewriting stored data automatically', function (): void {
    expect(MaharashtraGeography::canonicalDistrictName('Aurangabad'))->toBe('Chhatrapati Sambhajinagar')
        ->and(MaharashtraGeography::canonicalDistrictName('Osmanabad'))->toBe('Dharashiv')
        ->and(MaharashtraGeography::canonicalDistrictName('Ahmednagar'))->toBe('Ahilyanagar')
        ->and(MaharashtraGeography::canonicalTalukaName('Jalna', 'Ghansawangi'))->toBe('Ghansawangi')
        ->and(MaharashtraGeography::canonicalTalukaName('Jalna', 'Jafrabad'))->toBe('Jafferabad')
        ->and(MaharashtraGeography::canonicalTalukaName('Jalna', 'Haveli'))->toBeNull()
        ->and(count(MaharashtraGeography::districts()))->toBe(36)
        ->and(MaharashtraGeography::talukasForDistrict('Jalna'))->toContain('Ghansawangi');
});

it('returns the cached Maharashtra location tree for mobile and web', function (): void {
    $user = User::query()->create([
        'name' => 'Location User',
        'email' => 'location.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Employee->value,
    ]);

    $this->actingAs($user, 'sanctum');

    $response = $this->getJson('/api/locations/maharashtra')
        ->assertOk()
        ->assertJsonPath('state', 'Maharashtra');

    $districts = collect($response->json('districts'));
    expect($districts)->toHaveCount(36);

    $jalna = $districts->firstWhere('name', 'Jalna');
    expect($jalna['talukas'])->toContain('Ambad')
        ->and($jalna['talukas'])->toContain('Ghansawangi')
        ->and($jalna['talukas'])->toContain('Jafferabad');

    $renamed = $districts->firstWhere('name', 'Chhatrapati Sambhajinagar');
    expect($renamed['former_name'])->toBe('Aurangabad');
});
