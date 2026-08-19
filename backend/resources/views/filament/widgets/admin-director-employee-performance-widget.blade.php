<x-filament-widgets::widget class="fi-admin-director-employee-performance-widget">
    <div class="pg-admin-dash">
        <x-filament::section>
            <div class="pg-section-head">
                <div>
                    <h2 class="pg-section-title">Employee Performance</h2>
                    <p class="pg-section-sub">{{ $periodLabel }}</p>
                </div>

                <div class="manager-employee-filters">
                    <div class="pg-seg" role="group" aria-label="Performance period filters">
                        <button type="button" wire:click="setAdminPeriod('today')" @class(['pg-seg__btn', 'pg-seg__btn--active' => $this->isActiveAdminPeriod('today')])>Today</button>
                        <button type="button" wire:click="setAdminPeriod('weekly')" @class(['pg-seg__btn', 'pg-seg__btn--active' => $this->isActiveAdminPeriod('weekly')])>Weekly</button>
                        <button type="button" wire:click="setAdminPeriod('monthly')" @class(['pg-seg__btn', 'pg-seg__btn--active' => $this->isActiveAdminPeriod('monthly')])>Monthly</button>
                        <button type="button" wire:click="setAdminPeriod('custom')" @class(['pg-seg__btn', 'pg-seg__btn--active' => $this->isActiveAdminPeriod('custom')])>Custom</button>
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
                <p class="pg-empty">No employee performance data found for the selected filters.</p>
            @else
                <div class="pg-table-wrap">
                    <table class="pg-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Role</th>
                                <th class="pg-num">Sales Target</th>
                                <th class="pg-num">Achievement</th>
                                <th>Achievement %</th>
                                <th class="pg-num">Collection</th>
                                <th>Performance</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($employees as $employee)
                                @php
                                    $salesPct = (float) ($employee['sales_percentage'] ?? 0);
                                    $barWidth = min(max($salesPct, 0), 100);
                                @endphp
                                <tr>
                                    <td>
                                        <p class="pg-emp-name">{{ $employee['employee_name'] }}</p>
                                        <p class="pg-emp-code">{{ filled($employee['employee_code']) ? $employee['employee_code'] : '—' }}</p>
                                    </td>
                                    <td>
                                        <span class="pg-role">{{ $employee['role_label'] ?? $employee['role'] ?? 'Employee' }}</span>
                                    </td>
                                    <td class="pg-num">{{ $formatMoney((float) $employee['sales_target']) }}</td>
                                    <td class="pg-num">{{ $formatMoney((float) $employee['sales_achieved']) }}</td>
                                    <td class="pg-num">{{ $formatPercentage($salesPct) }}</td>
                                    <td class="pg-num">{{ $formatMoney((float) ($employee['collection_achieved'] ?? 0)) }}</td>
                                    <td>
                                        <span class="pg-mini-track" aria-hidden="true">
                                            <span class="pg-mini-bar" style="width: {{ $barWidth }}%; display:block;"></span>
                                        </span>
                                        <span style="font-size:0.75rem;color:#64748B;font-weight:650;">{{ $formatPercentage($salesPct) }}</span>
                                    </td>
                                    <td>
                                        <a href="{{ $detailUrl($employee['employee_id']) }}" class="pg-link">View</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if (count($topPerformers) > 0)
                    <div class="pg-top3">
                        <p class="pg-top3__title">Top Performers</p>
                        @foreach ($topPerformers as $index => $row)
                            <div class="pg-top3__row">
                                <div>
                                    <span class="pg-top3__rank">{{ $index + 1 }}</span>
                                    {{ $row['employee_name'] }}
                                </div>
                                <span class="pg-top3__amt">{{ $formatMoney((float) ($row['sales_achieved'] ?? 0)) }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            @endif
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
