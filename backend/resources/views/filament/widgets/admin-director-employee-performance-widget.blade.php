<x-filament-widgets::widget class="fi-admin-director-employee-performance-widget">
    <div class="pg-admin-dash">
        <x-filament::section>
            <div class="pg-section-head">
                <div>
                    <h2 class="pg-section-title">Team Performance</h2>
                    <p class="pg-section-sub">This month’s sales achievement</p>
                </div>
            </div>

            <div class="pg-split">
                <div class="pg-split__col">
                    <h3 class="pg-split__title">Top Performers</h3>
                    @forelse ($topPerformers as $employee)
                        <a href="{{ $detailUrl($employee['employee_id']) }}" class="pg-person">
                            <span class="pg-person__name">{{ $employee['employee_name'] }}</span>
                            <span class="pg-person__pct pg-person__pct--good">{{ number_format((float) $employee['sales_percentage'], 0) }}%</span>
                        </a>
                    @empty
                        <p class="pg-empty">No achievement recorded yet this month.</p>
                    @endforelse
                </div>
                <div class="pg-split__col">
                    <h3 class="pg-split__title">Needs Attention</h3>
                    @forelse ($needsAttention as $employee)
                        <a href="{{ $detailUrl($employee['employee_id']) }}" class="pg-person">
                            <span class="pg-person__name">{{ $employee['employee_name'] }}</span>
                            <span class="pg-person__pct pg-person__pct--warn">{{ number_format((float) $employee['sales_percentage'], 0) }}%</span>
                        </a>
                    @empty
                        <p class="pg-empty">No combined activity or target gaps right now.</p>
                    @endforelse
                </div>
            </div>
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
