<x-filament-widgets::widget class="fi-admin-director-team-activity-widget">
    <div class="pg-admin-dash">
        <x-filament::section>
            <div class="pg-section-head">
                <div>
                    <h2 class="pg-section-title">Team Activity Today</h2>
                    <p class="pg-section-sub">Attendance, field work, and live routes</p>
                </div>
            </div>

            <div class="pg-team__strip">
                @foreach ($metrics as $metric)
                    <a href="{{ $metric['url'] }}" class="pg-team__cell" aria-label="{{ $metric['label'] }}: {{ $metric['value'] }}">
                        <div>
                            <p class="pg-team__label">{{ $metric['label'] }}</p>
                            <p class="pg-team__value">{{ $metric['value'] }}</p>
                        </div>
                    </a>
                @endforeach
            </div>
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
