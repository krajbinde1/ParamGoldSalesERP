<x-filament-widgets::widget class="fi-manager-team-performance-widget">
    @include('filament.widgets.partials.manager-dashboard-styles')

    <x-filament::section class="manager-dashboard-section">
        <div class="manager-employee-section-header">
            <div class="manager-employee-section-header__title-wrap">
                <h2 class="manager-employee-section-header__title">Overall Team Performance</h2>
                <p class="manager-employee-section-header__subtitle">{{ $periodLabel }}</p>
            </div>

            <div class="manager-employee-filters">
                <div class="manager-period-filters" role="group" aria-label="Team performance period filters">
                    <button
                        type="button"
                        wire:click="setTeamPeriod('today')"
                        @class(['manager-period-btn', 'manager-period-btn--active' => $this->isActiveTeamPeriod('today')])
                    >
                        Today
                    </button>
                    <button
                        type="button"
                        wire:click="setTeamPeriod('weekly')"
                        @class(['manager-period-btn', 'manager-period-btn--active' => $this->isActiveTeamPeriod('weekly')])
                    >
                        Weekly
                    </button>
                    <button
                        type="button"
                        wire:click="setTeamPeriod('monthly')"
                        @class(['manager-period-btn', 'manager-period-btn--active' => $this->isActiveTeamPeriod('monthly')])
                    >
                        Monthly
                    </button>
                    <button
                        type="button"
                        wire:click="setTeamPeriod('custom')"
                        @class(['manager-period-btn', 'manager-period-btn--active' => $this->isActiveTeamPeriod('custom')])
                    >
                        Custom
                    </button>
                </div>
            </div>
        </div>

        @if ($showCustomPeriod)
            <div class="manager-custom-period-row">
                <div class="manager-custom-period-field">
                    <label for="team-custom-from-date" class="manager-custom-period-label">From Date</label>
                    <input
                        id="team-custom-from-date"
                        type="date"
                        wire:model="teamCustomFromDate"
                        class="manager-custom-period-input"
                    >
                    @error('teamCustomFromDate')
                        <p class="manager-custom-period-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="manager-custom-period-field">
                    <label for="team-custom-to-date" class="manager-custom-period-label">To Date</label>
                    <input
                        id="team-custom-to-date"
                        type="date"
                        wire:model="teamCustomToDate"
                        class="manager-custom-period-input"
                    >
                    @error('teamCustomToDate')
                        <p class="manager-custom-period-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="manager-custom-period-actions">
                    <button type="button" wire:click="applyTeamCustomPeriod" class="manager-period-action-btn manager-period-action-btn--apply">
                        Apply
                    </button>
                    <button type="button" wire:click="resetTeamCustomPeriod" class="manager-period-action-btn manager-period-action-btn--clear">
                        Reset
                    </button>
                </div>
            </div>
        @endif

        <div class="manager-team-stats-grid">
            <div class="manager-team-stat-card manager-team-stat-card--sales-target">
                <p class="manager-team-stat-card__label">Total Sales Target</p>
                <p class="manager-team-stat-card__value">{{ $formatMoney((float) $summary['sales_target']) }}</p>
            </div>

            <div class="manager-team-stat-card manager-team-stat-card--sales-achievement">
                <p class="manager-team-stat-card__label">Total Sales Achievement</p>
                <p class="manager-team-stat-card__value">{{ $formatMoney((float) $summary['sales_achieved']) }}</p>
            </div>

            <div class="manager-team-stat-card manager-team-stat-card--collection-target">
                <p class="manager-team-stat-card__label">Total Collection Target</p>
                <p class="manager-team-stat-card__value">{{ $formatMoney((float) $summary['collection_target']) }}</p>
            </div>

            <div class="manager-team-stat-card manager-team-stat-card--collection-achievement">
                <p class="manager-team-stat-card__label">Total Collection Achievement</p>
                <p class="manager-team-stat-card__value">{{ $formatMoney((float) $summary['collection_achieved']) }}</p>
            </div>
        </div>

        <div class="manager-team-progress-grid">
            <div class="manager-team-progress-card">
                <div class="manager-metric-row">
                    <span class="manager-metric-row__label">Overall Sales %</span>
                    <span class="manager-metric-row__value">{{ $formatPercentage((float) $summary['sales_percentage']) }}</span>
                </div>
                <div class="manager-progress-track" aria-hidden="true">
                    <div
                        class="manager-progress-bar manager-progress-bar--sales"
                        style="width: {{ $salesBarWidth }}%;"
                    ></div>
                </div>
            </div>

            <div class="manager-team-progress-card">
                <div class="manager-metric-row">
                    <span class="manager-metric-row__label">Overall Collection %</span>
                    <span class="manager-metric-row__value">{{ $formatPercentage((float) $summary['collection_percentage']) }}</span>
                </div>
                <div class="manager-progress-track" aria-hidden="true">
                    <div
                        class="manager-progress-bar manager-progress-bar--collection"
                        style="width: {{ $collectionBarWidth }}%;"
                    ></div>
                </div>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
