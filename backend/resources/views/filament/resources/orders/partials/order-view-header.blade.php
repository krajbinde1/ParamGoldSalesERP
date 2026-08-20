@php
    use App\Models\Order;
    use Filament\Support\Enums\Alignment;

    /** @var Order $record */
    $record->loadMissing('salesEmployee:id,full_name');

    $statusLabel = $record->displayStatusLabel();
    $statusColor = Order::statusColor((string) $record->status);
    $badgeStyles = match ($statusColor) {
        'success' => 'background:#ECFDF5;color:#047857;border:1px solid #A7F3D0;',
        'warning' => 'background:#FFFBEB;color:#B45309;border:1px solid #FDE68A;',
        'danger' => 'background:#FEF2F2;color:#B91C1C;border:1px solid #FECACA;',
        'info' => 'background:#EFF6FF;color:#1D4ED8;border:1px solid #BFDBFE;',
        'primary' => 'background:#F0FDFA;color:#0F766E;border:1px solid #99F6E4;',
        default => 'background:#F8FAFC;color:#475569;border:1px solid #E2E8F0;',
    };

    $orderDate = $record->order_date
        ? $record->order_date->format('d M Y')
        : '—';
    $salesEmployee = $record->salesEmployee?->full_name ?: '—';
    $heading = 'Order #'.$record->shortOrderNo();
    $actionsAlignment = $actionsAlignment ?? Alignment::Start;
@endphp

<style>
    .pg-order-view-header.fi-header {
        align-items: flex-start;
        gap: 1rem;
        margin-bottom: 1.25rem;
    }
    .pg-order-view-header__title {
        margin: 0;
        font-size: 1.5rem;
        font-weight: 700;
        line-height: 1.25;
        color: #0F172A;
        letter-spacing: -0.02em;
    }
    .pg-order-view-header__badge {
        display: inline-block;
        margin-top: 0.5rem;
        padding: 0.2rem 0.65rem;
        border-radius: 9999px;
        font-size: 0.75rem;
        font-weight: 700;
        line-height: 1.35;
    }
    .pg-order-view-header__meta {
        margin: 0.5rem 0 0;
        padding: 0;
        list-style: none;
        display: flex;
        flex-direction: column;
        gap: 0.15rem;
    }
    .pg-order-view-header__meta li {
        margin: 0;
        font-size: 0.8125rem;
        line-height: 1.4;
        color: #64748B;
    }
    .pg-order-view-header__meta-label {
        color: #94A3B8;
    }
    .pg-order-view-header .fi-header-actions-ctn {
        flex-shrink: 0;
    }
</style>

<header class="fi-header pg-order-view-header">
    <div>
        @if (! empty($breadcrumbs))
            <x-filament::breadcrumbs :breadcrumbs="$breadcrumbs" />
        @endif

        <h1 class="pg-order-view-header__title">{{ $heading }}</h1>

        <span class="pg-order-view-header__badge" style="{{ $badgeStyles }}">
            {{ $statusLabel }}
        </span>

        <ul class="pg-order-view-header__meta">
            <li>
                <span class="pg-order-view-header__meta-label">Order Date:</span>
                {{ $orderDate }}
            </li>
            <li>
                <span class="pg-order-view-header__meta-label">Sales Employee:</span>
                {{ $salesEmployee }}
            </li>
        </ul>
    </div>

    @if (! empty($actions))
        <div class="fi-header-actions-ctn">
            <x-filament::actions
                :actions="$actions"
                :alignment="$actionsAlignment"
            />
        </div>
    @endif
</header>
