<x-filament-widgets::widget class="fi-admin-director-business-performance-widget">
    <div class="pg-admin-dash">
        <x-filament::section>
            <div class="pg-section-head">
                <div>
                    <h2 class="pg-section-title">Business Performance</h2>
                    <p class="pg-section-sub">{{ $periodLabel }}</p>
                </div>
                <div class="pg-seg" role="group" aria-label="Business performance period filters">
                    @foreach (['today' => 'Today', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'custom' => 'Custom'] as $key => $label)
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

            @php
                $metrics = [
                    [
                        'label' => 'Sales Target',
                        'value' => $formatMoney((float) $summary['sales_target']),
                        'hint' => (float) $summary['sales_target'] <= 0 ? 'No target assigned' : null,
                        'tone' => 'teal',
                        'icon' => 'heroicon-o-flag',
                    ],
                    [
                        'label' => 'Sales Achievement',
                        'value' => $formatMoney((float) $summary['sales_achieved']),
                        'hint' => $formatPercentage((float) $summary['sales_percentage']).' of target',
                        'tone' => 'green',
                        'icon' => 'heroicon-o-arrow-trending-up',
                    ],
                    [
                        'label' => 'Collection Target',
                        'value' => $formatMoney((float) $summary['collection_target']),
                        'hint' => (float) $summary['collection_target'] <= 0 ? 'No target assigned' : null,
                        'tone' => 'blue',
                        'icon' => 'heroicon-o-building-library',
                    ],
                    [
                        'label' => 'Collection Achievement',
                        'value' => $formatMoney((float) $summary['collection_achieved']),
                        'hint' => $formatPercentage((float) $summary['collection_percentage']).' of target',
                        'tone' => 'blue',
                        'icon' => 'heroicon-o-banknotes',
                    ],
                ];
            @endphp

            <div class="pg-metric-grid">
                @foreach ($metrics as $metric)
                    <div class="pg-metric">
                        <div class="pg-metric__top">
                            <p class="pg-metric__label">{{ $metric['label'] }}</p>
                            <div class="pg-icon pg-icon--{{ $metric['tone'] }}" aria-hidden="true">
                                <x-filament::icon :icon="$metric['icon']" />
                            </div>
                        </div>
                        <p class="pg-metric__value">{{ $metric['value'] }}</p>
                        @if ($metric['hint'])
                            <p class="pg-metric__hint">{{ $metric['hint'] }}</p>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="pg-progress-grid">
                <div class="pg-progress">
                    <div class="pg-progress__row">
                        <p class="pg-progress__label">Sales Performance</p>
                        <p class="pg-progress__pct">{{ $formatPercentage((float) $summary['sales_percentage']) }}</p>
                    </div>
                    <p class="pg-progress__amounts">
                        {{ $formatMoney((float) $summary['sales_achieved']) }}
                        /
                        @if ((float) $summary['sales_target'] <= 0)
                            No target assigned
                        @else
                            {{ $formatMoney((float) $summary['sales_target']) }}
                        @endif
                    </p>
                    <div class="pg-progress__track">
                        <div class="pg-progress__bar" style="width: {{ $salesBarWidth }}%;"></div>
                    </div>
                </div>
                <div class="pg-progress">
                    <div class="pg-progress__row">
                        <p class="pg-progress__label">Collection Performance</p>
                        <p class="pg-progress__pct">{{ $formatPercentage((float) $summary['collection_percentage']) }}</p>
                    </div>
                    <p class="pg-progress__amounts">
                        {{ $formatMoney((float) $summary['collection_achieved']) }}
                        /
                        @if ((float) $summary['collection_target'] <= 0)
                            No target assigned
                        @else
                            {{ $formatMoney((float) $summary['collection_target']) }}
                        @endif
                    </p>
                    <div class="pg-progress__track">
                        <div class="pg-progress__bar pg-progress__bar--blue" style="width: {{ $collectionBarWidth }}%;"></div>
                    </div>
                </div>
            </div>
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
