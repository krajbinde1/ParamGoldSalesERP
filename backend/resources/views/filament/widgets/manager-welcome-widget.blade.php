<x-filament-widgets::widget class="fi-manager-welcome-widget">
    <x-filament::section>
        <div class="space-y-1">
            <h2 class="text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
                Welcome, {{ $managerName }}
            </h2>
            <p class="text-sm text-gray-600 dark:text-gray-300">
                Role: {{ $roleLabel }}
            </p>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ $currentDate }}
            </p>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
