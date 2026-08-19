<x-filament-widgets::widget class="fi-admin-director-business-performance-widget">
    <x-filament::section>
        <div class="pg-dash-section__head">
            <div>
                <h2 class="pg-dash-section__title">Overall Business Performance</h2>
                <p class="pg-dash-section__subtitle">{{ $periodLabel }}</p>
            </div>
            <div class="pg-dash-seg" role="group" aria-label="Business performance period filters">
                @foreach (['today' => 'Today', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'custom' => 'Custom'] as $key => $label)
                    <button
                        type="button"
                        wire:click="setBizPeriod('{{ $key }}')"
                        @class(['pg-dash-seg__btn', 'pg-dash-seg__btn--active' => $this->isActiveBizPeriod($key)])
                    >{{ $label }}</button>
                @endforeach
            </div>
        </div>

        @if ($showCustomPeriod)
            <div class="manager-custom-period-row">
                <div class="manager-custom-period-field">
                    <label for="biz-custom-from-date" class="manager-custom-period-label">From Date</label>
                    <input id="biz-custom-from-date" type="date" wire:model="bizCustomFromDate" class="manager-custom-period-input">
                    @error('bizCustomFromDate')<p class="manager-custom-period-error">{{ $message }}</p>@enderror
                </div>
                <div class="manager-custom-period-field">
                    <label for="biz-custom-to-date" class="manager-custom-period-label">To Date</label>
                    <input id="biz-custom-to-date" type="date" wire:model="bizCustomToDate" class="manager-custom-period-input">
                    @error('bizCustomToDate')<p class="manager-custom-period-error">{{ $message }}</p>@enderror
                </div>
                <div class="manager-custom-period-actions">
                    <button type="button" wire:click="applyBizCustomPeriod" class="manager-period-action-btn manager-period-action-btn--apply">Apply</button>
                    <button type="button" wire:click="resetBizCustomPeriod" class="manager-period-action-btn manager-period-action-btn--clear">Reset</button>
                </div>
            </div>
        @endif

        <div class="pg-dash-metric-grid">
            <div class="pg-dash-metric">
                <div class="pg-dash-metric__top">
                    <p class="pg-dash-metric__label">Sales Target</p>
                    <div class="pg-dash-icon pg-dash-icon--teal" aria-hidden="true"><span class="pg-dash-icon__glyph">◎</span></div>
                </div>
                <p class="pg-dash-metric__value">{{ $formatMoney((float) $summary['sales_target']) }}</p>
                @if ((float) $summary['sales_target'] <= 0)
                    <p class="pg-dash-metric__hint">No target assigned</p>
                @endif
            </div>
            <div class="pg-dash-metric">
                <div class="pg-dash-metric__top">
                    <p class="pg-dash-metric__label">Sales Achievement</p>
                    <div class="pg-dash-icon pg-dash-icon--green" aria-hidden="true"><span class="pg-dash-icon__glyph">◆</span></div>
                </div>
                <p class="pg-dash-metric__value">{{ $formatMoney((float) $summary['sales_achieved']) }}</p>
                <p class="pg-dash-metric__hint">{{ $formatPercentage((float) $summary['sales_percentage']) }} of target</p>
            </div>
            <div class="pg-dash-metric">
                <div class="pg-dash-metric__top">
                    <p class="pg-dash-metric__label">Collection Target</p>
                    <div class="pg-dash-icon pg-dash-icon--blue" aria-hidden="true"><span class="pg-dash-icon__glyph">◎</span></div>
                </div>
                <p class="pg-dash-metric__value">{{ $formatMoney((float) $summary['collection_target']) }}</p>
                @if ((float) $summary['collection_target'] <= 0)
                    <p class="pg-dash-metric__hint">No target assigned</p>
                @endif
            </div>
            <div class="pg-dash-metric">
                <div class="pg-dash-metric__top">
                    <p class="pg-dash-metric__label">Collection Achievement</p>
                    <div class="pg-dash-icon pg-dash-icon--blue" aria-hidden="true"><span class="pg-dash-icon__glyph">◆</span></div>
                </div>
                <p class="pg-dash-metric__value">{{ $formatMoney((float) $summary['collection_achieved']) }}</p>
                <p class="pg-dash-metric__hint">{{ $formatPercentage((float) $summary['collection_percentage']) }} of target</p>
            </div>
        </div>

        <div class="pg-dash-progress-grid">
            <div class="pg-dash-progress">
                <div class="pg-dash-progress__row">
                    <p class="pg-dash-progress__label">Sales Achievement</p>
                    <p class="pg-dash-progress__pct">{{ $formatPercentage((float) $summary['sales_percentage']) }}</p>
                </div>
                <p class="pg-dash-progress__amounts">
                    {{ $formatMoney((float) $summary['sales_achieved']) }}
                    /
                    {{ $formatMoney((float) $summary['sales_target']) }}
                </p>
                <div class="pg-dash-progress__track">
                    <div class="pg-dash-progress__bar" style="width: {{ $salesBarWidth }}%;"></div>
                </div>
            </div>
            <div class="pg-dash-progress">
                <div class="pg-dash-progress__row">
                    <p class="pg-dash-progress__label">Collection Achievement</p>
                    <p class="pg-dash-progress__pct">{{ $formatPercentage((float) $summary['collection_percentage']) }}</p>
                </div>
                <p class="pg-dash-progress__amounts">
                    {{ $formatMoney((float) $summary['collection_achieved']) }}
                    /
                    {{ $formatMoney((float) $summary['collection_target']) }}
                </p>
                <div class="pg-dash-progress__track">
                    <div class="pg-dash-progress__bar pg-dash-progress__bar--collection" style="width: {{ $collectionBarWidth }}%;"></div>
                </div>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
