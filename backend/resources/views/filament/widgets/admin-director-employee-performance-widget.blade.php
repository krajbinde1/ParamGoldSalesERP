<x-filament-widgets::widget class="fi-admin-director-employee-performance-widget">
    <div class="pg-admin-dash">
        <x-filament::section>
            <div class="pg-section-head">
                <div>
                    <h2 class="pg-section-title">Team Performance</h2>
                    <p class="pg-section-sub">Employee-wise monthly target vs achievement · {{ $monthLabel }}</p>
                </div>
            </div>

            @forelse ($employees as $employee)
                @php
                    $salesPct = (float) $employee['sales_percentage'];
                    $collectionPct = (float) $employee['collection_percentage'];
                    $fieldPct = (float) $employee['field_activity_percentage'];
                    $overallPct = (float) $employee['overall_percentage'];
                    $overallClass = $overallPct >= 80
                        ? 'pg-team-card__overall--good'
                        : ($overallPct < 50 ? 'pg-team-card__overall--warn' : '');
                @endphp
                <article class="pg-team-card">
                    <div class="pg-team-card__head">
                        <a href="{{ $detailUrl((int) $employee['employee_id']) }}" class="pg-team-card__name">
                            {{ $employee['employee_name'] }}
                        </a>
                        <span class="pg-team-card__overall {{ $overallClass }}">
                            Overall {{ $formatPct($overallPct) }}
                        </span>
                        <a
                            href="{{ $whatsappUrl($employee) }}"
                            class="pg-team-card__wa"
                            target="_blank"
                            rel="noopener noreferrer"
                            aria-label="Share {{ $employee['employee_name'] }} performance on WhatsApp"
                        >
                            Share on WhatsApp
                        </a>
                    </div>

                    <div class="pg-team-card__metrics">
                        <div class="pg-team-metric">
                            <div class="pg-team-metric__row">
                                <span class="pg-team-metric__label">Sales</span>
                                <span class="pg-team-metric__pct">{{ $formatPct($salesPct) }}</span>
                            </div>
                            <p class="pg-team-metric__values">
                                Target {{ $formatMoney((float) $employee['sales_target']) }}
                                · Achieved {{ $formatMoney((float) $employee['sales_achieved']) }}
                            </p>
                            <div class="pg-progress__track" aria-hidden="true">
                                <div class="pg-progress__bar" style="width: {{ $barWidth($salesPct) }}%;"></div>
                            </div>
                        </div>

                        <div class="pg-team-metric">
                            <div class="pg-team-metric__row">
                                <span class="pg-team-metric__label">Collection</span>
                                <span class="pg-team-metric__pct">{{ $formatPct($collectionPct) }}</span>
                            </div>
                            <p class="pg-team-metric__values">
                                Target {{ $formatMoney((float) $employee['collection_target']) }}
                                · Achieved {{ $formatMoney((float) $employee['collection_achieved']) }}
                            </p>
                            <div class="pg-progress__track" aria-hidden="true">
                                <div class="pg-progress__bar pg-progress__bar--blue" style="width: {{ $barWidth($collectionPct) }}%;"></div>
                            </div>
                        </div>

                        <div class="pg-team-metric">
                            <div class="pg-team-metric__row">
                                <span class="pg-team-metric__label">Field Activity</span>
                                <span class="pg-team-metric__pct">{{ $formatPct($fieldPct) }}</span>
                            </div>
                            <p class="pg-team-metric__values">
                                Target {{ (int) $employee['field_activity_target'] }}
                                · Achieved {{ (int) $employee['field_activity_achieved'] }}
                            </p>
                            <div class="pg-progress__track" aria-hidden="true">
                                <div class="pg-progress__bar pg-progress__bar--field" style="width: {{ $barWidth($fieldPct) }}%;"></div>
                            </div>
                        </div>
                    </div>
                </article>
            @empty
                <p class="pg-empty">No sales employees found for this month.</p>
            @endforelse
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
