@php
    use App\Support\IndianCurrency;

    $asOn = $summary['opening_balance_date']
        ? \Illuminate\Support\Carbon::parse($summary['opening_balance_date'])->format('d M Y')
        : null;
@endphp

<style>
    .pg-dealer-account-summary {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.75rem;
    }
    @media (min-width: 768px) {
        .pg-dealer-account-summary {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }
    }
    .pg-dealer-account-card {
        border: 1px solid #E2E8F0;
        border-radius: 0.85rem;
        background: #FFFFFF;
        padding: 0.9rem 1rem;
        min-height: 5.5rem;
    }
    .pg-dealer-account-card__label {
        margin: 0;
        font-size: 0.75rem;
        font-weight: 600;
        letter-spacing: 0.02em;
        text-transform: uppercase;
        color: #64748B;
    }
    .pg-dealer-account-card__value {
        margin: 0.35rem 0 0;
        font-size: 1.15rem;
        font-weight: 700;
        color: #0F172A;
        letter-spacing: -0.02em;
    }
    .pg-dealer-account-card__meta {
        margin: 0.2rem 0 0;
        font-size: 0.75rem;
        color: #94A3B8;
    }
    .pg-dealer-account-card.is-outstanding {
        background: #FEF2F2;
        border-color: #FECACA;
    }
    .pg-dealer-account-card.is-outstanding .pg-dealer-account-card__label {
        color: #B91C1C;
    }
    .pg-dealer-account-card.is-outstanding .pg-dealer-account-card__value {
        color: #991B1B;
        font-size: 1.25rem;
    }
    .pg-dealer-account-exposure {
        margin-top: 0.85rem;
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem 1.25rem;
        color: #475569;
        font-size: 0.8125rem;
    }
    .pg-dealer-account-actions {
        margin-top: 1rem;
    }
    .pg-dealer-account-actions a {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 0.65rem;
        background: #0F766E;
        color: #FFFFFF;
        font-size: 0.8125rem;
        font-weight: 600;
        padding: 0.55rem 0.9rem;
        text-decoration: none;
    }
    .pg-dealer-account-actions a:hover {
        background: #115E59;
    }
</style>

<div class="pg-dealer-account-summary">
    <div class="pg-dealer-account-card">
        <p class="pg-dealer-account-card__label">Opening Balance</p>
        <p class="pg-dealer-account-card__value">{{ IndianCurrency::format($summary['opening_balance']) }}</p>
        <p class="pg-dealer-account-card__meta">{{ $asOn ? 'As on '.$asOn : 'As On Date not set' }}</p>
    </div>
    <div class="pg-dealer-account-card">
        <p class="pg-dealer-account-card__label">Billed Sales</p>
        <p class="pg-dealer-account-card__value">{{ IndianCurrency::format($summary['billed_sales']) }}</p>
    </div>
    <div class="pg-dealer-account-card">
        <p class="pg-dealer-account-card__label">Collections Received</p>
        <p class="pg-dealer-account-card__value">{{ IndianCurrency::format($summary['collections_received']) }}</p>
    </div>
    <div class="pg-dealer-account-card is-outstanding">
        <p class="pg-dealer-account-card__label">Current Outstanding</p>
        <p class="pg-dealer-account-card__value">{{ IndianCurrency::format($summary['current_outstanding']) }}</p>
    </div>
</div>

<div class="pg-dealer-account-exposure">
    <span>Unbilled Orders: <strong>{{ IndianCurrency::format($summary['unbilled_orders']) }}</strong></span>
    <span>Total Exposure: <strong>{{ IndianCurrency::format($summary['total_exposure']) }}</strong></span>
</div>

@if (! empty($ledgerUrl))
    <div class="pg-dealer-account-actions">
        <a href="{{ $ledgerUrl }}">View Full Ledger</a>
    </div>
@endif
