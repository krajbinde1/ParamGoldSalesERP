<?php

use App\Enums\UserRole;
use App\Filament\Pages\AppUpdateSettings;
use App\Models\MobileAppSetting;
use App\Models\User;
use App\Services\MobileApp\MobileApkPublisher;
use App\Services\MobileApp\MobileAppVersionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    cleanupPublishedApk();
});

afterEach(function (): void {
    cleanupPublishedApk();
});

function cleanupPublishedApk(): void
{
    $path = app(MobileApkPublisher::class)->publicPath();
    foreach ([$path, $path.'.bak', $path.'.uploading'] as $file) {
        if (is_file($file)) {
            File::delete($file);
        }
    }
}

function apkAdmin(): User
{
    return User::query()->create([
        'name' => 'APK Admin',
        'email' => 'apk.admin.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Admin',
    ]);
}

function fakeReleaseApk(string $name = 'app-release.apk', string $marker = 'PARAMGOLD-APK'): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        "PK\x03\x04".$marker.str_repeat('x', 2048),
    );
}

function publishedApkSettingsPayload(array $overrides = []): array
{
    return array_merge([
        'latest_version' => '1.0.4',
        'latest_build' => 6,
        'force_update' => true,
        'apk_url' => MobileAppVersionService::DEFAULT_APK_URL,
        'update_message' => 'Please update to continue.',
        'apk' => null,
    ], $overrides);
}

it('lets admin save version settings without uploading an apk', function (): void {
    $admin = apkAdmin();

    Livewire::actingAs($admin)
        ->test(AppUpdateSettings::class)
        ->fillForm(publishedApkSettingsPayload())
        ->call('save')
        ->assertHasNoFormErrors();

    expect(MobileAppSetting::query()->first()?->latest_build)->toBe(6)
        ->and(is_file(app(MobileApkPublisher::class)->publicPath()))->toBeFalse();

    $this->getJson('/api/app-version')
        ->assertOk()
        ->assertJsonPath('latest_version', '1.0.4')
        ->assertJsonPath('latest_build', 6)
        ->assertJsonPath('force_update', true)
        ->assertJsonPath('apk_url', MobileAppVersionService::DEFAULT_APK_URL);
});

it('replaces the public apk and verifies the download url when admin saves a release apk', function (): void {
    $admin = apkAdmin();
    $apk = fakeReleaseApk();

    Livewire::actingAs($admin)
        ->test(AppUpdateSettings::class)
        ->fillForm(publishedApkSettingsPayload([
            'apk' => $apk,
        ]))
        ->call('save')
        ->assertHasNoFormErrors();

    $path = app(MobileApkPublisher::class)->publicPath();
    expect(is_file($path))->toBeTrue()
        ->and(file_get_contents($path))->toContain('PARAMGOLD-APK')
        ->and(MobileAppSetting::query()->first()?->latest_build)->toBe(6);

    $download = $this->get('/apk/paramgold-latest.apk');
    $download->assertOk()
        ->assertHeader('content-type', 'application/vnd.android.package-archive')
        ->assertHeader('content-disposition', 'attachment; filename="paramgold-latest.apk"');

    expect($download->baseResponse->getFile()->getContent())->toContain('PARAMGOLD-APK');
});

it('does not replace the current apk or version settings when the upload is not a valid apk', function (): void {
    $admin = apkAdmin();
    $publisher = app(MobileApkPublisher::class);
    $path = $publisher->publicPath();
    File::ensureDirectoryExists(dirname($path));
    file_put_contents($path, "PK\x03\x04OLD-APK".str_repeat('o', 2048));

    MobileAppSetting::query()->create([
        'latest_version' => '1.0.3',
        'latest_build' => 5,
        'force_update' => true,
        'apk_url' => MobileAppVersionService::DEFAULT_APK_URL,
        'update_message' => 'Please update.',
        'updated_by' => $admin->id,
    ]);

    Livewire::actingAs($admin)
        ->test(AppUpdateSettings::class)
        ->fillForm(publishedApkSettingsPayload([
            'latest_version' => '1.0.4',
            'latest_build' => 6,
            'apk' => UploadedFile::fake()->createWithContent(
                'app-release.apk',
                str_repeat('not-an-apk', 300),
            ),
        ]))
        ->call('save')
        ->assertHasFormErrors(['apk']);

    expect(MobileAppSetting::query()->first()?->latest_build)->toBe(5)
        ->and(file_get_contents($path))->toContain('OLD-APK');
});

it('rejects a non-apk filename before replacing the published file', function (): void {
    $admin = apkAdmin();

    Livewire::actingAs($admin)
        ->test(AppUpdateSettings::class)
        ->fillForm(publishedApkSettingsPayload([
            'apk' => UploadedFile::fake()->createWithContent(
                'notes.txt',
                "PK\x03\x04".str_repeat('x', 2048),
            ),
        ]))
        ->call('save')
        ->assertHasFormErrors();

    expect(MobileAppSetting::query()->count())->toBe(0)
        ->and(is_file(app(MobileApkPublisher::class)->publicPath()))->toBeFalse();
});

it('restores the previous apk when replacement verification would leave a missing file', function (): void {
    $publisher = app(MobileApkPublisher::class);
    $path = $publisher->publicPath();
    File::ensureDirectoryExists(dirname($path));
    file_put_contents($path, "PK\x03\x04OLD-APK".str_repeat('o', 2048));

    expect(fn () => $publisher->replaceLatest(
        UploadedFile::fake()->createWithContent('app-release.apk', str_repeat('plain-text', 300)),
    ))->toThrow(ValidationException::class);

    expect(file_get_contents($path))->toContain('OLD-APK');
});

it('serves the apk download without authentication', function (): void {
    $publisher = app(MobileApkPublisher::class);
    File::ensureDirectoryExists(dirname($publisher->publicPath()));
    file_put_contents($publisher->publicPath(), "PK\x03\x04PUBLIC".str_repeat('x', 2048));

    $download = $this->get('/apk/paramgold-latest.apk');
    $download->assertOk();

    expect($download->baseResponse->getFile()->getContent())->toContain('PUBLIC');
});
