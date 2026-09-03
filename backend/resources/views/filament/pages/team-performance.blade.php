<x-filament-panels::page>
    <div class="pg-admin-dash">
        <x-filament::section>
            <div class="pg-section-head">
                <div>
                    <p class="pg-section-sub">{{ $this->periodLabel() }}</p>
                </div>
                <div class="pg-seg" role="group" aria-label="Team performance period filters">
                    @foreach ($this->periodFilters() as $key => $label)
                        <button
                            type="button"
                            wire:click="setPeriod('{{ $key }}')"
                            @class(['pg-seg__btn', 'pg-seg__btn--active' => $this->isActivePeriod($key)])
                        >{{ $label }}</button>
                    @endforeach
                </div>
            </div>

            @if ($this->showCustomPeriod())
                <div class="manager-custom-period-row">
                    <div class="manager-custom-period-field">
                        <label for="team-perf-custom-from-date" class="manager-custom-period-label">From Date</label>
                        <input id="team-perf-custom-from-date" type="date" wire:model="customFromDate" class="manager-custom-period-input">
                        @error('customFromDate')<p class="manager-custom-period-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="manager-custom-period-field">
                        <label for="team-perf-custom-to-date" class="manager-custom-period-label">To Date</label>
                        <input id="team-perf-custom-to-date" type="date" wire:model="customToDate" class="manager-custom-period-input">
                        @error('customToDate')<p class="manager-custom-period-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="manager-custom-period-actions">
                        <button type="button" wire:click="applyCustomPeriod" class="manager-period-action-btn manager-period-action-btn--apply">Apply</button>
                        <button type="button" wire:click="resetCustomPeriod" class="manager-period-action-btn manager-period-action-btn--clear">Reset</button>
                    </div>
                </div>
            @endif

            @forelse ($this->employees() as $employee)
                @php
                    $employeeId = (int) $employee['employee_id'];
                    $salesPct = (float) $employee['sales_percentage'];
                    $collectionPct = (float) $employee['collection_percentage'];
                    $fieldPct = (float) $employee['field_activity_percentage'];
                    $overallPct = (float) $employee['overall_percentage'];
                    $overallClass = $overallPct >= 80
                        ? 'pg-team-card__overall--good'
                        : ($overallPct < 50 ? 'pg-team-card__overall--warn' : '');
                @endphp
                <article class="pg-team-card" wire:key="team-emp-{{ $employeeId }}">
                    <div class="pg-team-card__head">
                        <span class="pg-team-card__name">{{ $employee['employee_name'] }}</span>
                        <span class="pg-team-card__overall {{ $overallClass }}">
                            Overall {{ $this->formatPct($overallPct) }}
                        </span>
                        <a
                            href="{{ $this->whatsappUrl($employee) }}"
                            class="pg-team-card__wa"
                            target="_blank"
                            rel="noopener noreferrer"
                            aria-label="Share {{ $employee['employee_name'] }} performance on WhatsApp"
                        >
                            Share on WhatsApp
                        </a>
                    </div>

                    <div class="pg-team-card__metrics">
                        <button
                            type="button"
                            class="pg-team-metric {{ $this->isMetricActive($employeeId, 'sales') ? 'pg-team-metric--active' : '' }}"
                            wire:click="openDetail({{ $employeeId }}, 'sales')"
                            aria-pressed="{{ $this->isMetricActive($employeeId, 'sales') ? 'true' : 'false' }}"
                        >
                            <div class="pg-team-metric__row">
                                <span class="pg-team-metric__label">Sales</span>
                                <span class="pg-team-metric__pct">{{ $this->formatPct($salesPct) }}</span>
                            </div>
                            <p class="pg-team-metric__values">
                                Target {{ $this->formatMoney((float) $employee['sales_target']) }}
                                · Achieved {{ $this->formatMoney((float) $employee['sales_achieved']) }}
                            </p>
                            <div class="pg-progress__track" aria-hidden="true">
                                <div class="pg-progress__bar" style="width: {{ $this->barWidth($salesPct) }}%;"></div>
                            </div>
                        </button>

                        <button
                            type="button"
                            class="pg-team-metric {{ $this->isMetricActive($employeeId, 'collection') ? 'pg-team-metric--active' : '' }}"
                            wire:click="openDetail({{ $employeeId }}, 'collection')"
                            aria-pressed="{{ $this->isMetricActive($employeeId, 'collection') ? 'true' : 'false' }}"
                        >
                            <div class="pg-team-metric__row">
                                <span class="pg-team-metric__label">Collection</span>
                                <span class="pg-team-metric__pct">{{ $this->formatPct($collectionPct) }}</span>
                            </div>
                            <p class="pg-team-metric__values">
                                Target {{ $this->formatMoney((float) $employee['collection_target']) }}
                                · Achieved {{ $this->formatMoney((float) $employee['collection_achieved']) }}
                            </p>
                            <div class="pg-progress__track" aria-hidden="true">
                                <div class="pg-progress__bar pg-progress__bar--blue" style="width: {{ $this->barWidth($collectionPct) }}%;"></div>
                            </div>
                        </button>

                        <button
                            type="button"
                            class="pg-team-metric {{ $this->isMetricActive($employeeId, 'field_activity') ? 'pg-team-metric--active' : '' }}"
                            wire:click="openDetail({{ $employeeId }}, 'field_activity')"
                            aria-pressed="{{ $this->isMetricActive($employeeId, 'field_activity') ? 'true' : 'false' }}"
                        >
                            <div class="pg-team-metric__row">
                                <span class="pg-team-metric__label">Field Activity</span>
                                <span class="pg-team-metric__pct">{{ $this->formatPct($fieldPct) }}</span>
                            </div>
                            <p class="pg-team-metric__values">
                                Target {{ (int) $employee['field_activity_target'] }}
                                · Achieved {{ (int) $employee['field_activity_achieved'] }}
                            </p>
                            <div class="pg-progress__track" aria-hidden="true">
                                <div class="pg-progress__bar pg-progress__bar--field" style="width: {{ $this->barWidth($fieldPct) }}%;"></div>
                            </div>
                        </button>
                    </div>

                    @if ($this->isMetricActive($employeeId, 'sales') || $this->isMetricActive($employeeId, 'collection') || $this->isMetricActive($employeeId, 'field_activity'))
                        <div class="pg-team-detail">
                            <div class="pg-team-detail__head">
                                <h3 class="pg-team-detail__title">{{ $this->detailHeading() }}</h3>
                                <button type="button" class="pg-team-detail__close" wire:click="closeDetail">Close</button>
                            </div>

                            @php $rows = $this->detailRows(); @endphp

                            @if ($rows === [])
                                <p class="pg-empty">No records found for this employee in the selected period.</p>
                            @elseif ($this->detailType === 'sales')
                                <div class="pg-team-detail__table-wrap">
                                    <table class="pg-team-detail__table">
                                        <thead>
                                            <tr>
                                                <th>Order No</th>
                                                <th>Date</th>
                                                <th>Dealer</th>
                                                <th class="pg-team-detail__num">Order Amount</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($rows as $row)
                                                <tr>
                                                    <td>
                                                        <a href="{{ $row['url'] }}">{{ $row['short_order_no'] ?: $row['order_no'] }}</a>
                                                    </td>
                                                    <td>{{ $this->formatDate($row['order_date'] ?? null) }}</td>
                                                    <td>{{ $row['dealer_name'] ?: '—' }}</td>
                                                    <td class="pg-team-detail__num">{{ $this->formatExactMoney((float) $row['grand_total']) }}</td>
                                                    <td>{{ $row['status_label'] }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <th colspan="3">{{ $this->detailTotalLabel() }}</th>
                                                <td class="pg-team-detail__num">{{ $this->detailTotalDisplay() }}</td>
                                                <td></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            @elseif ($this->detailType === 'collection')
                                <div class="pg-team-detail__table-wrap">
                                    <table class="pg-team-detail__table">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Dealer</th>
                                                <th class="pg-team-detail__num">Amount</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($rows as $row)
                                                <tr>
                                                    <td>
                                                        <a href="{{ $row['url'] }}">{{ $this->formatDate($row['collection_date'] ?? null) }}</a>
                                                    </td>
                                                    <td>{{ $row['dealer_name'] ?: '—' }}</td>
                                                    <td class="pg-team-detail__num">{{ $this->formatExactMoney((float) $row['amount']) }}</td>
                                                    <td>{{ $row['status_label'] }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <th colspan="2">{{ $this->detailTotalLabel() }}</th>
                                                <td class="pg-team-detail__num">{{ $this->detailTotalDisplay() }}</td>
                                                <td></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            @else
                                <div class="pg-team-detail__table-wrap">
                                    <table class="pg-team-detail__table">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Dealer / Farmer</th>
                                                <th>Village</th>
                                                <th>Activity details</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($rows as $row)
                                                <tr>
                                                    <td>
                                                        <a href="{{ $row['url'] }}">{{ $this->formatDate($row['activity_date'] ?? null) }}</a>
                                                    </td>
                                                    <td>{{ $row['farmer_name'] ?: '—' }}</td>
                                                    <td>{{ $row['village'] ?: '—' }}</td>
                                                    <td>{{ $row['details'] }}</td>
                                                    <td>{{ $row['status_label'] }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <th colspan="4">{{ $this->detailTotalLabel() }}</th>
                                                <td>{{ $this->detailTotalDisplay() }}</td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            @endif
                        </div>
                    @endif
                </article>
            @empty
                <p class="pg-empty">No sales employees found for this period.</p>
            @endforelse
        </x-filament::section>
    </div>
</x-filament-panels::page>
