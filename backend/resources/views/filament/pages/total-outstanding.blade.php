<x-filament-panels::page>
    @php
        $selectedEmployeeId = $this->selectedEmployeeId();
        $employeeRows = $this->employeeOutstandingRows();
        $topDealers = $this->topOutstandingDealers();
    @endphp

    <div class="pg-admin-dash to-dashboard">
        <div class="pg-kpi-grid">
            <div class="pg-card pg-kpi to-kpi to-kpi--hero" aria-label="Total Outstanding: {{ $this->formattedTotalOutstanding() }}">
                <div class="pg-icon pg-icon--red" aria-hidden="true">
                    <x-filament::icon icon="heroicon-o-banknotes" />
                </div>
                <div>
                    <p class="pg-kpi__value">{{ $this->formattedTotalOutstanding() }}</p>
                    <p class="pg-kpi__label">Total Outstanding</p>
                </div>
            </div>

            <div class="pg-card pg-kpi to-kpi" aria-label="Credit Balance: {{ $this->formattedCreditBalance() }}">
                <div class="pg-icon pg-icon--green" aria-hidden="true">
                    <x-filament::icon icon="heroicon-o-arrow-uturn-left" />
                </div>
                <div>
                    <p class="pg-kpi__value">{{ $this->formattedCreditBalance() }}</p>
                    <p class="pg-kpi__label">Credit Balance</p>
                </div>
            </div>

            <div class="pg-card pg-kpi to-kpi" aria-label="Outstanding Dealers: {{ $this->outstandingDealerCount() }}">
                <div class="pg-icon pg-icon--slate" aria-hidden="true">
                    <x-filament::icon icon="heroicon-o-building-storefront" />
                </div>
                <div>
                    <p class="pg-kpi__value">{{ $this->outstandingDealerCount() }}</p>
                    <p class="pg-kpi__label">Outstanding Dealers</p>
                </div>
            </div>

            <div class="pg-card pg-kpi to-kpi" aria-label="High Outstanding: {{ $this->highOutstandingDealerCount() }}">
                <div class="pg-icon pg-icon--amber" aria-hidden="true">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" />
                </div>
                <div>
                    <p class="pg-kpi__value">{{ $this->highOutstandingDealerCount() }}</p>
                    <p class="pg-kpi__label">High Outstanding</p>
                </div>
            </div>
        </div>

        <div class="pg-card to-filter">
            <div class="to-filter__field">
                {{ $this->form }}
            </div>
            @if ($selectedEmployeeId !== null)
                <button type="button" class="to-filter__reset" wire:click="resetFilter">
                    View All Employees
                </button>
            @endif
        </div>

        <section class="pg-card to-section" aria-labelledby="to-employee-heading">
            <div class="to-section__head">
                <div>
                    <h2 id="to-employee-heading" class="to-section__title">Outstanding by Employee</h2>
                    <p class="to-section__sub">Highest receivable first. Select a card to filter dealers and exports.</p>
                </div>
            </div>

            @if ($employeeRows === [])
                <p class="to-empty">No employee outstanding to show.</p>
            @else
                <div class="to-employee-grid">
                    @foreach ($employeeRows as $index => $row)
                        @php
                            $isSelected = $selectedEmployeeId === (int) $row['employee_id'];
                            $dealerLabel = (int) ($row['outstanding_dealer_count'] ?? $row['dealer_count']);
                        @endphp
                        <button
                            type="button"
                            wire:click="selectEmployee({{ $row['employee_id'] }})"
                            class="to-emp-card{{ $isSelected ? ' to-emp-card--selected' : '' }}"
                        >
                            <div class="to-emp-card__top">
                                <span class="to-emp-card__rank">#{{ $index + 1 }}</span>
                                <div class="to-emp-card__who">
                                    <p class="to-emp-card__name">{{ $row['employee_name'] }}</p>
                                    @if (filled($row['employee_code']))
                                        <p class="to-emp-card__code">{{ $row['employee_code'] }}</p>
                                    @endif
                                </div>
                            </div>
                            <p class="to-emp-card__dealers">{{ $dealerLabel }} {{ $dealerLabel === 1 ? 'Dealer' : 'Dealers' }}</p>
                            <p class="to-emp-card__amount">{{ $this->formatMoney((float) $row['total_outstanding']) }}</p>
                            <p class="to-emp-card__amount-label">Outstanding</p>
                            @if ((float) $row['total_credit'] > 0)
                                <p class="to-emp-card__credit">
                                    Credit Balance: {{ $this->formatMoney((float) $row['total_credit']) }}
                                </p>
                            @endif
                        </button>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="pg-card to-section" aria-labelledby="to-top-heading">
            <div class="to-section__head">
                <div>
                    <h2 id="to-top-heading" class="to-section__title">Highest Outstanding Dealers</h2>
                    <p class="to-section__sub">Top 5 dealers by positive outstanding{{ $selectedEmployeeId !== null ? ' for the selected employee' : '' }}.</p>
                </div>
            </div>

            @if ($topDealers === [])
                <p class="to-empty">No dealers with outstanding for this view.</p>
            @else
                <ol class="to-rank-list">
                    @foreach ($topDealers as $index => $dealer)
                        <li class="to-rank-item">
                            <span class="to-rank-item__n">{{ $index + 1 }}</span>
                            <div class="to-rank-item__body">
                                <p class="to-rank-item__name">{{ $dealer['dealer_name'] }}</p>
                                <p class="to-rank-item__emp">{{ $dealer['employee_name'] }}</p>
                            </div>
                            <p class="to-rank-item__amt">{{ $this->formatMoney((float) $dealer['outstanding']) }}</p>
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>

        <div class="to-dealer-table inventory-reports-table-wrap">
            {{ $this->table }}
        </div>
    </div>
</x-filament-panels::page>
