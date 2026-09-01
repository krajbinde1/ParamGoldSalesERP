<x-filament-widgets::widget class="fi-admin-director-business-performance-widget">
    <div class="pg-admin-dash">
        <x-filament::section>
            <div class="pg-section-head">
                <div>
                    <h2 class="pg-section-title">{{ $heading }}</h2>
                    <p class="pg-section-sub">{{ $periodLabel }}</p>
                </div>
                <div class="pg-seg" role="group" aria-label="Business performance period filters">
                    @foreach ($periodFilters as $key => $label)
                        <button
                            type="button"
                            wire:click="setBizPeriod('{{ $key }}')"
                            @class(['pg-seg__btn', 'pg-seg__btn--active' => $this->isActiveBizPeriod($key)])
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

            <div class="pg-progress-grid">
                <a href="{{ $salesUrl }}" class="pg-progress">
                    <div class="pg-progress__row">
                        <p class="pg-progress__label">Sales</p>
                        <p class="pg-progress__pct">{{ $formatPercentage((float) $summary['sales_percentage']) }}</p>
                    </div>
                    <p class="pg-progress__amounts">
                        Target: {{ $formatMoney((float) $summary['sales_target']) }}
                        · Achievement: {{ $formatMoney((float) $summary['sales_achieved']) }}
                    </p>
                    <div class="pg-progress__track">
                        <div class="pg-progress__bar" style="width: {{ $salesBarWidth }}%;"></div>
                    </div>
                    <p class="pg-progress__remain">Remaining: {{ $salesRemaining }}</p>
                </a>
                <a href="{{ $collectionUrl }}" class="pg-progress">
                    <div class="pg-progress__row">
                        <p class="pg-progress__label">Collection</p>
                        <p class="pg-progress__pct">{{ $formatPercentage((float) $summary['collection_percentage']) }}</p>
                    </div>
                    <p class="pg-progress__amounts">
                        Target: {{ $formatMoney((float) $summary['collection_target']) }}
                        · Achievement: {{ $formatMoney((float) $summary['collection_achieved']) }}
                    </p>
                    <div class="pg-progress__track">
                        <div class="pg-progress__bar pg-progress__bar--blue" style="width: {{ $collectionBarWidth }}%;"></div>
                    </div>
                    <p class="pg-progress__remain">Remaining: {{ $collectionRemaining }}</p>
                </a>
            </div>
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
