<x-filament-widgets::widget class="fi-admin-director-employee-performance-widget">
    @include('filament.partials.paramgold-admin-theme')

    <x-filament::section class="manager-dashboard-section">
        <div class="manager-employee-section-header">
            <div class="manager-employee-section-header__title-wrap">
                <h2 class="manager-employee-section-header__title">Employee Performance</h2>
                <p class="manager-employee-section-header__subtitle">{{ $periodLabel }}</p>
            </div>

            <div class="manager-employee-filters">
                <div class="manager-period-filters" role="group" aria-label="Performance period filters">
                    <button type="button" wire:click="setAdminPeriod('today')" @class(['manager-period-btn', 'manager-period-btn--active' => $this->isActiveAdminPeriod('today')])>Today</button>
                    <button type="button" wire:click="setAdminPeriod('weekly')" @class(['manager-period-btn', 'manager-period-btn--active' => $this->isActiveAdminPeriod('weekly')])>Weekly</button>
                    <button type="button" wire:click="setAdminPeriod('monthly')" @class(['manager-period-btn', 'manager-period-btn--active' => $this->isActiveAdminPeriod('monthly')])>Monthly</button>
                    <button type="button" wire:click="setAdminPeriod('custom')" @class(['manager-period-btn', 'manager-period-btn--active' => $this->isActiveAdminPeriod('custom')])>Custom</button>
                </div>

                <div class="manager-employee-filter-select">
                    <input type="search" wire:model.live.debounce.300ms="employeeSearch" class="manager-employee-search" placeholder="Search employees..." aria-label="Search employees">
                    <select wire:model.live="employeeId" class="manager-employee-select" aria-label="Employee filter">
                        <option value="all">All Employees</option>
                        @foreach ($employeeOptions as $option)
                            <option value="{{ $option['id'] }}">{{ $option['name'] }}{{ filled($option['code']) ? ' ('.$option['code'].')' : '' }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        @if ($showCustomPeriod)
            <div class="manager-custom-period-row">
                <div class="manager-custom-period-field">
                    <label for="admin-custom-from-date" class="manager-custom-period-label">From Date</label>
                    <input id="admin-custom-from-date" type="date" wire:model="customFromDate" class="manager-custom-period-input">
                    @error('customFromDate')<p class="manager-custom-period-error">{{ $message }}</p>@enderror
                </div>
                <div class="manager-custom-period-field">
                    <label for="admin-custom-to-date" class="manager-custom-period-label">To Date</label>
                    <input id="admin-custom-to-date" type="date" wire:model="customToDate" class="manager-custom-period-input">
                    @error('customToDate')<p class="manager-custom-period-error">{{ $message }}</p>@enderror
                </div>
                <div class="manager-custom-period-actions">
                    <button type="button" wire:click="applyAdminCustomPeriod" class="manager-period-action-btn manager-period-action-btn--apply">Apply</button>
                    <button type="button" wire:click="resetAdminCustomPeriod" class="manager-period-action-btn manager-period-action-btn--clear">Reset</button>
                </div>
            </div>
        @endif

        @if (count($employees) === 0)
            <p class="manager-empty-state">No employee performance data found for the selected filters.</p>
        @else
            <div class="manager-employee-grid">
                @foreach ($employees as $employee)
                    @php
                        $salesPercentage = (float) ($employee['sales_percentage'] ?? 0);
                        $collectionPercentage = (float) ($employee['collection_percentage'] ?? 0);
                        $salesBarWidth = min($salesPercentage, 100);
                        $collectionBarWidth = min($collectionPercentage, 100);
                    @endphp
                    <article class="manager-employee-card">
                        <div class="manager-employee-card__header">
                            <div class="manager-employee-card__identity">
                                <h3 class="manager-employee-card__name">{{ $employee['employee_name'] }}</h3>
                                <p class="manager-employee-card__code">{{ filled($employee['employee_code']) ? $employee['employee_code'] : 'No employee code' }}</p>
                            </div>
                            <a href="{{ $detailUrl($employee['employee_id']) }}" class="manager-view-performance-btn">View Performance</a>
                        </div>
                        <div class="manager-employee-card__section" style="margin-top: 0; padding-top: 0; border-top: 0;">
                            <p class="manager-employee-card__section-title">Sales Performance</p>
                            <div class="manager-metric-row"><span class="manager-metric-row__label">Target</span><span class="manager-metric-row__value">{{ $formatMoney((float) $employee['sales_target']) }}</span></div>
                            <div class="manager-metric-row"><span class="manager-metric-row__label">Achievement</span><span class="manager-metric-row__value">{{ $formatMoney((float) $employee['sales_achieved']) }}</span></div>
                            <div class="manager-metric-row"><span class="manager-metric-row__label">Progress</span><span class="manager-metric-row__value">{{ $formatPercentage($salesPercentage) }}</span></div>
                            <div class="manager-progress-track"><div class="manager-progress-bar manager-progress-bar--sales" style="width: {{ $salesBarWidth }}%;"></div></div>
                        </div>
                        <div class="manager-employee-card__section">
                            <p class="manager-employee-card__section-title">Collection Performance</p>
                            <div class="manager-metric-row"><span class="manager-metric-row__label">Target</span><span class="manager-metric-row__value">{{ $formatMoney((float) $employee['collection_target']) }}</span></div>
                            <div class="manager-metric-row"><span class="manager-metric-row__label">Achievement</span><span class="manager-metric-row__value">{{ $formatMoney((float) $employee['collection_achieved']) }}</span></div>
                            <div class="manager-metric-row"><span class="manager-metric-row__label">Progress</span><span class="manager-metric-row__value">{{ $formatPercentage($collectionPercentage) }}</span></div>
                            <div class="manager-progress-track"><div class="manager-progress-bar manager-progress-bar--collection" style="width: {{ $collectionBarWidth }}%;"></div></div>
                        </div>
                        <div class="manager-employee-card__section" style="margin-top: auto;">
                            <p class="manager-employee-card__section-title">Order Summary</p>
                            <div class="manager-order-summary-grid">
                                <div class="manager-order-badge manager-order-badge--pending"><p class="manager-order-badge__label">Pending</p><p class="manager-order-badge__count">{{ $employee['pending_orders'] }}</p></div>
                                <div class="manager-order-badge manager-order-badge--approved"><p class="manager-order-badge__label">Approved</p><p class="manager-order-badge__count">{{ $employee['approved_orders'] }}</p></div>
                                <div class="manager-order-badge manager-order-badge--dispatched"><p class="manager-order-badge__label">Dispatched</p><p class="manager-order-badge__count">{{ $employee['dispatched_orders'] }}</p></div>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
