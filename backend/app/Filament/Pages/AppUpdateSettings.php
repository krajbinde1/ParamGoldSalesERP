<?php

namespace App\Filament\Pages;

use App\Services\MobileApp\MobileAppVersionService;
use BackedEnum;
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
use Illuminate\Validation\ValidationException;

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
        ]);
    }

    public function form(Schema $schema): Schema
    {
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
                    ])
                    ->columns(1),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        try {
            $state = $this->form->getState();
            app(MobileAppVersionService::class)->save($state, auth()->user());
        } catch (ValidationException $e) {
            throw $e;
        }

        $this->refreshCurrentSettings();

        Notification::make()
            ->title('App update settings saved')
            ->body('GET /api/app-version now returns these values. No .env change is required.')
            ->success()
            ->send();
    }

    public function usesConfigFallback(): bool
    {
        return ($this->currentSettings['source'] ?? 'config') === 'config';
    }

    private function refreshCurrentSettings(): void
    {
        $this->currentSettings = app(MobileAppVersionService::class)->current();
    }
}
