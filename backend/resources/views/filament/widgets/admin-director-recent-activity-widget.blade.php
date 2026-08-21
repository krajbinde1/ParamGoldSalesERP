<x-filament-widgets::widget class="fi-admin-director-recent-activity-widget">
    <div class="pg-admin-dash">
        <x-filament::section>
            <div class="pg-section-head">
                <div>
                    <h2 class="pg-section-title">Recent Important Activity</h2>
                    <p class="pg-section-sub">Latest orders, payments, collections, and punch-ins</p>
                </div>
            </div>

            @if (count($activities) === 0)
                <p class="pg-empty">No recent activity to show.</p>
            @else
                <ul class="pg-activity">
                    @foreach ($activities as $activity)
                        <li class="pg-activity__item">
                            <div>
                                <p class="pg-activity__text">{{ $activity['text'] }}</p>
                                @if (filled($activity['meta'] ?? null))
                                    <p class="pg-activity__meta">{{ $activity['meta'] }}</p>
                                @endif
                            </div>
                            <p class="pg-activity__when">{{ $activity['date'] }} · {{ $activity['time'] }}</p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
