<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
            <div class="text-sm font-medium text-gray-950 dark:text-white">Current published values</div>
            <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                @if ($this->usesConfigFallback())
                    Using config / .env fallback until settings are saved in the database.
                @else
                    Served from the database. Mobile apps read these values from GET /api/app-version.
                @endif
            </div>

            <dl class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Current Latest Version</dt>
                    <dd class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $this->currentSettings['latest_version'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Current Latest Build</dt>
                    <dd class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $this->currentSettings['latest_build'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Force Update Status</dt>
                    <dd class="mt-1 text-lg font-semibold {{ $this->currentSettings['force_update'] ? 'text-red-600 dark:text-red-400' : 'text-gray-950 dark:text-white' }}">
                        {{ $this->currentSettings['force_update'] ? 'ON' : 'OFF' }}
                    </dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">APK URL</dt>
                    <dd class="mt-1 break-all text-sm font-medium text-gray-950 dark:text-white">{{ $this->currentSettings['apk_url'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Last Updated At</dt>
                    <dd class="mt-1 text-sm font-medium text-gray-950 dark:text-white">{{ $this->currentSettings['updated_at'] ?? 'Not saved yet' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Updated By</dt>
                    <dd class="mt-1 text-sm font-medium text-gray-950 dark:text-white">{{ $this->currentSettings['updated_by_name'] ?? '—' }}</dd>
                </div>
            </dl>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
            <form wire:submit="save" class="space-y-6">
                {{ $this->form }}

                <div class="flex flex-wrap items-center gap-3">
                    <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">Save Settings</span>
                        <span wire:loading wire:target="save">Saving...</span>
                    </x-filament::button>
                </div>
            </form>
        </div>
    </div>
</x-filament-panels::page>
