<x-filament-widgets::widget class="fi-admin-director-quick-actions-widget">
    <x-filament::section class="manager-dashboard-section">
        <x-slot name="heading">Quick Actions</x-slot>

        <div class="paramgold-quick-actions-grid">
            @foreach ($actions as $action)
                <a href="{{ $action['url'] }}" class="paramgold-quick-action paramgold-quick-action--{{ $action['color'] }}">
                    {{ $action['label'] }}
                </a>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
