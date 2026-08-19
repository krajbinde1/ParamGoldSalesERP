<x-filament-widgets::widget class="fi-admin-director-employee-performance-widget">
    <x-filament::section>
        <div class="pg-dash-section__head">
            <div>
                <h2 class="pg-dash-section__title">Employee Performance</h2>
                <p class="pg-dash-section__subtitle">{{ $periodLabel }}</p>
            </div>

            <div class="manager-employee-filters">
                <div class="pg-dash-seg" role="group" aria-label="Performance period filters">
                    <button type="button" wire:click="setAdminPeriod('today')" @class(['pg-dash-seg__btn', 'pg-dash-seg__btn--active' => $this->isActiveAdminPeriod('today')])>Today</button>
                    <button type="button" wire:click="setAdminPeriod('weekly')" @class(['pg-dash-seg__btn', 'pg-dash-seg__btn--active' => $this->isActiveAdminPeriod('weekly')])>Weekly</button>
                    <button type="button" wire:click="setAdminPeriod('monthly')" @class(['pg-dash-seg__btn', 'pg-dash-seg__btn--active' => $this->isActiveAdminPeriod('monthly')])>Monthly</button>
                    <button type="button" wire:click="setAdminPeriod('custom')" @class(['pg-dash-seg__btn', 'pg-dash-seg__btn--active' => $this->isActiveAdminPeriod('custom')])>Custom</button>
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
            <p class="pg-dash-empty">No employee performance data found for the selected filters.</p>
        @else
            <div class="pg-dash-table-wrap">
                <table class="pg-dash-table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Role</th>
                            <th>Sales Target</th>
                            <th>Achievement</th>
                            <th>Achievement %</th>
                            <th>Collection</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($employees as $employee)
                            @php
                                $salesPct = (float) ($employee['sales_percentage'] ?? 0);
                                $collectionAchieved = (float) ($employee['collection_achieved'] ?? 0);
                                $pill = $salesPct >= 80
                                    ? ['class' => 'pg-dash-pill--good', 'label' => 'Top performer']
                                    : ($salesPct < 40
                                        ? ['class' => 'pg-dash-pill--warn', 'label' => 'Needs attention']
                                        : ['class' => 'pg-dash-pill--neutral', 'label' => 'On track']);
                            @endphp
                            <tr>
                                <td>
                                    <p class="pg-dash-emp-name">{{ $employee['employee_name'] }}</p>
                                    <p class="pg-dash-emp-code">{{ filled($employee['employee_code']) ? $employee['employee_code'] : '—' }}</p>
                                </td>
                                <td>{{ $employee['role_label'] ?? $employee['role'] ?? 'Employee' }}</td>
                                <td>{{ $formatMoney((float) $employee['sales_target']) }}</td>
                                <td>{{ $formatMoney((float) $employee['sales_achieved']) }}</td>
                                <td>{{ $formatPercentage($salesPct) }}</td>
                                <td>{{ $formatMoney($collectionAchieved) }}</td>
                                <td><span class="pg-dash-pill {{ $pill['class'] }}">{{ $pill['label'] }}</span></td>
                                <td>
                                    <a href="{{ $detailUrl($employee['employee_id']) }}" class="pg-dash-link">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
