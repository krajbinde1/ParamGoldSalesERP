<?php

use App\Enums\UserRole;
use App\Filament\Pages\AppUpdateSettings;
use App\Models\MobileAppSetting;
use App\Models\User;
use App\Services\MobileApp\MobileAppVersionService;
use Livewire\Livewire;

it('returns the public mobile app version payload from config when no settings row exists', function () {
    config([
        'mobile_app.latest_version' => '1.0.1',
        'mobile_app.latest_build' => 3,
        'mobile_app.apk_url' => 'https://paramgold.in/apk/paramgold-latest.apk',
        'mobile_app.force_update' => true,
        'mobile_app.message' => 'A new version of ParamGold is available. Please update to continue.',
    ]);

    expect(MobileAppSetting::query()->count())->toBe(0);

    $response = $this->getJson('/api/app-version');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('latest_version', '1.0.1')
        ->assertJsonPath('latest_build', 3)
        ->assertJsonPath('apk_url', 'https://paramgold.in/apk/paramgold-latest.apk')
        ->assertJsonPath('force_update', true)
        ->assertJsonPath('message', 'A new version of ParamGold is available. Please update to continue.');
});

it('does not require authentication', function () {
    $this->getJson('/api/app-version')->assertOk();
});

it('prefers database settings over config once a row exists', function () {
    config([
        'mobile_app.latest_version' => '1.0.0',
        'mobile_app.latest_build' => 2,
        'mobile_app.apk_url' => 'https://paramgold.in/apk/paramgold-latest.apk',
        'mobile_app.force_update' => true,
        'mobile_app.message' => 'config fallback',
    ]);

    MobileAppSetting::query()->create([
        'latest_version' => '1.0.3',
        'latest_build' => 5,
        'force_update' => true,
        'apk_url' => 'https://paramgold.in/apk/paramgold-latest.apk',
        'update_message' => 'A new version of ParamGold is available. Please update to continue.',
    ]);

    $this->getJson('/api/app-version')
        ->assertOk()
        ->assertJsonPath('latest_version', '1.0.3')
        ->assertJsonPath('latest_build', 5)
        ->assertJsonPath('message', 'A new version of ParamGold is available. Please update to continue.');
});

it('updates the single settings row instead of inserting duplicates', function () {
    $admin = User::query()->create([
        'name' => 'Admin User',
        'email' => 'admin.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Admin',
    ]);

    $service = app(MobileAppVersionService::class);

    $service->save([
        'latest_version' => '1.0.2',
        'latest_build' => 4,
        'force_update' => true,
        'apk_url' => 'https://paramgold.in/apk/paramgold-latest.apk',
        'update_message' => 'Update available.',
    ], $admin);

    $service->save([
        'latest_version' => '1.0.3',
        'latest_build' => 5,
        'force_update' => true,
        'apk_url' => 'https://paramgold.in/apk/paramgold-latest.apk',
        'update_message' => 'Please update.',
    ], $admin);

    expect(MobileAppSetting::query()->count())->toBe(1)
        ->and(MobileAppSetting::query()->first()?->latest_build)->toBe(5)
        ->and(MobileAppSetting::query()->first()?->updated_by)->toBe($admin->id);
});

it('does not allow latest build to be lowered', function () {
    $admin = User::query()->create([
        'name' => 'Admin User',
        'email' => 'admin.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Admin',
    ]);

    MobileAppSetting::query()->create([
        'latest_version' => '1.0.3',
        'latest_build' => 5,
        'force_update' => true,
        'apk_url' => 'https://paramgold.in/apk/paramgold-latest.apk',
        'update_message' => 'Please update.',
        'updated_by' => $admin->id,
    ]);

    expect(fn () => app(MobileAppVersionService::class)->save([
        'latest_version' => '1.0.2',
        'latest_build' => 4,
        'force_update' => true,
        'apk_url' => 'https://paramgold.in/apk/paramgold-latest.apk',
        'update_message' => 'Please update.',
    ], $admin))->toThrow(\Illuminate\Validation\ValidationException::class);

    expect(MobileAppSetting::query()->first()?->latest_build)->toBe(5);
});

it('allows only admin users to open app update settings', function () {
    $admin = User::query()->create([
        'name' => 'Admin User',
        'email' => 'admin.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Admin',
    ]);

    $director = User::query()->create([
        'name' => 'Director User',
        'email' => 'director.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Director',
    ]);

    $manager = User::query()->create([
        'name' => 'Manager User',
        'email' => 'manager.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Manager->value,
        'job_role' => 'Manager',
    ]);

    Livewire::actingAs($admin)
        ->test(AppUpdateSettings::class)
        ->assertSuccessful();

    Livewire::actingAs($director)
        ->test(AppUpdateSettings::class)
        ->assertForbidden();

    Livewire::actingAs($manager)
        ->test(AppUpdateSettings::class)
        ->assertForbidden();
});
