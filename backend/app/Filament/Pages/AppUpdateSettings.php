<?php

namespace App\Filament\Pages;

use App\Services\MobileApp\MobileApkPublisher;
use App\Services\MobileApp\MobileAppVersionService;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Throwable;

class AppUpdateSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'App Update Settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static ?string $title = 'App Update Settings';

    protected static ?string $slug = 'app-update-settings';

    protected string $view = 'filament.pages.app-update-settings';

    /** @var array<string, mixed> */
    public array $data = [];

    /**
     * @var array{
     *     latest_version: string,
     *     latest_build: int,
     *     apk_url: string,
     *     force_update: bool,
     *     message: string,
     *     source: string,
     *     updated_at: ?string,
     *     updated_by_name: ?string
     * }
     */
    public array $currentSettings = [];

    /**
     * @var array{exists: bool, size_bytes: ?int, updated_at: ?string, url_path: string}
     */
    public array $currentApk = [
        'exists' => false,
        'size_bytes' => null,
        'updated_at' => null,
        'url_path' => '/apk/paramgold-latest.apk',
    ];

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdminUser() === true;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->refreshCurrentSettings();
        $this->form->fill([
            'latest_version' => $this->currentSettings['latest_version'],
            'latest_build' => $this->currentSettings['latest_build'],
            'force_update' => $this->currentSettings['force_update'],
            'apk_url' => $this->currentSettings['apk_url'],
            'update_message' => $this->currentSettings['message'],
            'apk' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $maxKilobytes = MobileApkPublisher::MAX_KILOBYTES;

        return $schema
            ->components([
                Section::make('Mobile app version')
                    ->description('These values are served by GET /api/app-version. The app compares installed build number against Latest Build.')
                    ->schema([
                        TextInput::make('latest_version')
                            ->label('Latest Version')
                            ->required()
                            ->maxLength(32)
                            ->placeholder('1.0.3'),
                        TextInput::make('latest_build')
                            ->label('Latest Build')
                            ->required()
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->helperText(fn (): string => 'Must be '.$this->currentSettings['latest_build'].' or higher. Lowering the build number can skip required updates.')
                            ->rule(function (): \Closure {
                                return function (string $attribute, mixed $value, \Closure $fail): void {
                                    $currentBuild = (int) ($this->currentSettings['latest_build'] ?? 0);
                                    if ((int) $value < $currentBuild) {
                                        $fail("Latest Build cannot be lower than the currently published build ({$currentBuild}). Lowering it can skip required updates.");
                                    }
                                };
                            }),
                        Toggle::make('force_update')
                            ->label('Force Update')
                            ->helperText('Keep ON so installed apps below this build must update before continuing.')
                            ->default(true)
                            ->inline(false),
                        TextInput::make('apk_url')
                            ->label('APK URL')
                            ->required()
                            ->url()
                            ->rules(['regex:/^https:\\/\\//i'])
                            ->maxLength(2048)
                            ->placeholder(MobileAppVersionService::DEFAULT_APK_URL),
                        Textarea::make('update_message')
                            ->label('Update Message')
                            ->rows(3)
                            ->maxLength(2000)
                            ->placeholder(MobileAppVersionService::DEFAULT_MESSAGE),
                        FileUpload::make('apk')
                            ->label('Release APK')
                            ->helperText('Optional. Upload a release .apk to replace /apk/paramgold-latest.apk when you click Save Settings. Maximum 100 MB. If the upload fails, the current APK and version settings are not changed.')
                            ->acceptedFileTypes([
                                'application/vnd.android.package-archive',
                                'application/java-archive',
                                'application/zip',
                                'application/octet-stream',
                            ])
                            ->rules([
                                'nullable',
                                'file',
                                'extensions:apk',
                                'max:'.$maxKilobytes,
                            ])
                            ->maxSize($maxKilobytes)
                            ->disk('local')
                            ->visibility('private')
                            ->storeFiles(false)
                            ->downloadable(false)
                            ->openable(false)
                            ->previewable(false)
                            ->dehydrated(),
                    ])
                    ->columns(1),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $apkReplaced = false;

        try {
            $state = $this->form->getState();
            $uploaded = $this->uploadedApkFromState($state);

            if ($uploaded !== null) {
                app(MobileApkPublisher::class)->replaceLatest($uploaded);
                $apkReplaced = true;
            }

            unset($state['apk']);
            app(MobileAppVersionService::class)->save($state, auth()->user());
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            if (array_key_exists('apk', $errors) && ! array_key_exists('data.apk', $errors)) {
                $errors['data.apk'] = $errors['apk'];
            }

            $first = collect($errors)->flatten()->first();
            if (is_string($first) && str_contains(strtolower($first), 'apk')) {
                Notification::make()
                    ->danger()
                    ->title('APK upload failed')
                    ->body($first)
                    ->send();
            }

            throw ValidationException::withMessages($errors);
        } catch (Throwable $exception) {
            Notification::make()
                ->danger()
                ->title('APK upload failed')
                ->body($exception->getMessage().' The current APK and version settings were not changed.')
                ->send();

            return;
        }

        $this->data['apk'] = null;
        $this->refreshCurrentSettings();
        $this->form->fill([
            'latest_version' => $this->currentSettings['latest_version'],
            'latest_build' => $this->currentSettings['latest_build'],
            'force_update' => $this->currentSettings['force_update'],
            'apk_url' => $this->currentSettings['apk_url'],
            'update_message' => $this->currentSettings['message'],
            'apk' => null,
        ]);

        Notification::make()
            ->title('App update settings saved')
            ->body($apkReplaced
                ? 'Release APK replaced at /apk/paramgold-latest.apk and the download URL was verified. GET /api/app-version now returns these values.'
                : 'GET /api/app-version now returns these values. No .env change is required.')
            ->success()
            ->send();
    }

    public function usesConfigFallback(): bool
    {
        return ($this->currentSettings['source'] ?? 'config') === 'config';
    }

    public function currentApkSizeLabel(): string
    {
        $bytes = $this->currentApk['size_bytes'] ?? null;
        if (! $this->currentApk['exists'] || $bytes === null) {
            return 'Not uploaded yet';
        }

        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1).' MB';
        }

        return number_format($bytes / 1024, 0).' KB';
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function uploadedApkFromState(array $state): ?UploadedFile
    {
        $apk = $state['apk'] ?? null;
        if (is_array($apk)) {
            $apk = array_values($apk)[0] ?? null;
        }

        return $apk instanceof UploadedFile ? $apk : null;
    }

    private function refreshCurrentSettings(): void
    {
        $this->currentSettings = app(MobileAppVersionService::class)->current();
        $this->currentApk = app(MobileApkPublisher::class)->currentMeta();
    }
}
