{{-- Admin dashboard UI styles. Icon SVG sizing scoped under .pg-admin-dash — never global svg {}. --}}
<style>
    .pg-admin-dash {
        --pg-teal: #0F766E;
        --pg-teal-soft: rgba(15, 118, 110, 0.1);
        --pg-navy: #0F172A;
        --pg-muted: #64748B;
        --pg-border: #E2E8F0;
        --pg-bg: #F6F8FB;
        --pg-amber: #D97706;
        --pg-green: #16A34A;
        --pg-blue: #2563EB;
        --pg-red: #DC2626;
        font-family: inherit;
    }

    /* Sidebar teal polish (panel shell only — not page content cards) */
    .paramgold-admin-shell .fi-sidebar-nav {
        gap: 0.25rem;
    }

    .paramgold-admin-shell .fi-sidebar-group-label {
        margin-top: 0.75rem;
        margin-bottom: 0.35rem;
        font-size: 0.6875rem;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: #94A3B8;
    }

    .paramgold-admin-shell .fi-sidebar-item-button {
        border-radius: 0.55rem;
        transition: background-color 0.15s ease, color 0.15s ease;
    }

    .paramgold-admin-shell .fi-sidebar-item-button:hover {
        background: rgba(15, 118, 110, 0.06);
    }

    .paramgold-admin-shell .fi-sidebar-item.fi-active > .fi-sidebar-item-button,
    .paramgold-admin-shell .fi-sidebar-item-button[aria-current="page"] {
        background: rgba(15, 118, 110, 0.12) !important;
        color: var(--pg-navy, #0F172A) !important;
        box-shadow: inset 3px 0 0 #0F766E;
        font-weight: 650;
    }

    .paramgold-admin-shell .fi-sidebar-item.fi-active > .fi-sidebar-item-button .fi-icon,
    .paramgold-admin-shell .fi-sidebar-item-button[aria-current="page"] .fi-icon {
        color: #0F766E !important;
    }

    .paramgold-admin-shell .fi-sidebar-item-button .fi-icon,
    .paramgold-admin-shell .fi-sidebar-item-button svg.fi-icon {
        width: 1.125rem !important;
        height: 1.125rem !important;
        max-width: 1.125rem !important;
        max-height: 1.125rem !important;
    }

    .paramgold-admin-shell .fi-topbar {
        border-bottom: 1px solid #E2E8F0;
        background: rgba(255, 255, 255, 0.94);
    }

    /* ===== Dashboard surface ===== */
    .pg-admin-dash .pg-card {
        background: #fff;
        border: 1px solid var(--pg-border);
        border-radius: 0.75rem;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    }

    .pg-admin-dash .pg-section {
        padding: 1.125rem 1.25rem 1.25rem;
    }

    .pg-admin-dash .pg-section-head {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.75rem 1rem;
        margin-bottom: 1rem;
    }

    .pg-admin-dash .pg-section-title {
        margin: 0;
        font-size: 1.0625rem;
        font-weight: 700;
        letter-spacing: -0.015em;
        color: var(--pg-navy);
        line-height: 1.3;
    }

    .pg-admin-dash .pg-section-sub {
        margin: 0.2rem 0 0;
        font-size: 0.8125rem;
        color: var(--pg-muted);
        line-height: 1.4;
    }

    /* Scoped icon sizing — CRITICAL */
    .pg-admin-dash .pg-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 2.25rem;
        height: 2.25rem;
        flex-shrink: 0;
        border-radius: 0.625rem;
    }

    .pg-admin-dash .pg-icon svg,
    .pg-admin-dash .pg-icon .fi-icon {
        width: 1.125rem !important;
        height: 1.125rem !important;
        max-width: 1.125rem !important;
        max-height: 1.125rem !important;
        display: block;
    }

    .pg-admin-dash .pg-icon--teal { background: rgba(15, 118, 110, 0.12); color: #0F766E; }
    .pg-admin-dash .pg-icon--green { background: rgba(22, 163, 74, 0.12); color: #15803D; }
    .pg-admin-dash .pg-icon--amber { background: rgba(217, 119, 6, 0.12); color: #B45309; }
    .pg-admin-dash .pg-icon--blue { background: rgba(37, 99, 235, 0.12); color: #1D4ED8; }
    .pg-admin-dash .pg-icon--red { background: rgba(220, 38, 38, 0.12); color: #B91C1C; }
    .pg-admin-dash .pg-icon--slate { background: #F1F5F9; color: #475569; }

    /* Header */
    .pg-admin-dash .pg-header {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 1.125rem 1.25rem;
        margin-bottom: 1rem;
    }

    .pg-admin-dash .pg-live {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        margin: 0 0 0.4rem;
        padding: 0.15rem 0.55rem;
        border-radius: 9999px;
        background: rgba(15, 118, 110, 0.1);
        color: #0F766E;
        font-size: 0.6875rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    .pg-admin-dash .pg-live__dot {
        width: 0.4rem;
        height: 0.4rem;
        border-radius: 9999px;
        background: #10B981;
        box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.18);
    }

    .pg-admin-dash .pg-header__title {
        margin: 0;
        font-size: 1.375rem;
        font-weight: 750;
        letter-spacing: -0.02em;
        color: var(--pg-navy);
        line-height: 1.25;
    }

    .pg-admin-dash .pg-header__lead {
        margin: 0.3rem 0 0;
        font-size: 0.875rem;
        color: var(--pg-muted);
        line-height: 1.45;
        max-width: 36rem;
    }

    .pg-admin-dash .pg-header__date {
        margin: 0.35rem 0 0;
        font-size: 0.8125rem;
        font-weight: 550;
        color: #94A3B8;
    }

    .pg-admin-dash .pg-avatar {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 2.75rem;
        height: 2.75rem;
        border-radius: 9999px;
        background: linear-gradient(145deg, #0F766E, #14B8A6);
        color: #fff;
        font-size: 0.9375rem;
        font-weight: 750;
        flex-shrink: 0;
    }

    /* KPI row */
    .pg-admin-dash .pg-kpi-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.875rem;
        margin-bottom: 1rem;
    }

    @media (min-width: 720px) {
        .pg-admin-dash .pg-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }

    @media (min-width: 1120px) {
        .pg-admin-dash .pg-kpi-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    }

    .pg-admin-dash .pg-kpi {
        display: flex;
        align-items: flex-start;
        gap: 0.85rem;
        padding: 1rem 1.05rem;
        transition: box-shadow 0.15s ease, border-color 0.15s ease;
    }

    .pg-admin-dash .pg-kpi:hover {
        border-color: rgba(15, 118, 110, 0.28);
        box-shadow: 0 4px 14px rgba(15, 23, 42, 0.05);
    }

    .pg-admin-dash .pg-kpi__label {
        margin: 0;
        font-size: 0.75rem;
        font-weight: 650;
        letter-spacing: 0.02em;
        text-transform: uppercase;
        color: var(--pg-muted);
    }

    .pg-admin-dash .pg-kpi__value {
        margin: 0.35rem 0 0;
        font-size: 1.5rem;
        font-weight: 750;
        letter-spacing: -0.03em;
        color: var(--pg-navy);
        line-height: 1.15;
        word-break: break-word;
    }

    .pg-admin-dash .pg-kpi__meta {
        margin: 0.3rem 0 0;
        font-size: 0.75rem;
        color: #94A3B8;
    }

    /* Team strip */
    .pg-admin-dash .pg-team {
        margin-bottom: 1rem;
        padding: 1rem 1.15rem 1.05rem;
    }

    .pg-admin-dash .pg-team__strip {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0;
        margin-top: 0.85rem;
        border: 1px solid var(--pg-border);
        border-radius: 0.7rem;
        overflow: hidden;
        background: #FAFBFC;
    }

    @media (min-width: 900px) {
        .pg-admin-dash .pg-team__strip {
            grid-template-columns: repeat(5, minmax(0, 1fr));
        }
    }

    .pg-admin-dash .pg-team__cell {
        display: flex;
        align-items: center;
        gap: 0.7rem;
        padding: 0.85rem 1rem;
        min-width: 0;
        border-bottom: 1px solid var(--pg-border);
    }

    @media (min-width: 900px) {
        .pg-admin-dash .pg-team__cell {
            border-bottom: 0;
            border-right: 1px solid var(--pg-border);
        }
        .pg-admin-dash .pg-team__cell:last-child { border-right: 0; }
    }

    @media (max-width: 899px) {
        .pg-admin-dash .pg-team__cell:nth-last-child(-n+2) { border-bottom: 0; }
        .pg-admin-dash .pg-team__cell:nth-child(odd) { border-right: 1px solid var(--pg-border); }
    }

    .pg-admin-dash .pg-team__label {
        margin: 0;
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--pg-muted);
    }

    .pg-admin-dash .pg-team__value {
        margin: 0.1rem 0 0;
        font-size: 1.25rem;
        font-weight: 750;
        color: var(--pg-navy);
        line-height: 1;
    }

    /* Segmented filters */
    .pg-admin-dash .pg-seg {
        display: inline-flex;
        flex-wrap: wrap;
        gap: 0.2rem;
        padding: 0.2rem;
        border-radius: 0.65rem;
        background: #F1F5F9;
        border: 1px solid var(--pg-border);
    }

    .pg-admin-dash .pg-seg__btn {
        border: 0;
        border-radius: 0.5rem;
        background: transparent;
        padding: 0.4rem 0.75rem;
        font-size: 0.75rem;
        font-weight: 650;
        color: #475569;
        cursor: pointer;
        line-height: 1.2;
        transition: background-color 0.15s ease, color 0.15s ease;
    }

    .pg-admin-dash .pg-seg__btn:hover { color: #0F766E; }

    .pg-admin-dash .pg-seg__btn--active {
        background: #0F766E;
        color: #fff;
        box-shadow: 0 1px 2px rgba(15, 118, 110, 0.25);
    }

    /* Metrics */
    .pg-admin-dash .pg-metric-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.75rem;
    }

    @media (min-width: 720px) {
        .pg-admin-dash .pg-metric-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }

    @media (min-width: 1100px) {
        .pg-admin-dash .pg-metric-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    }

    .pg-admin-dash .pg-metric {
        padding: 0.95rem 1rem;
        background: #FAFBFC;
        border: 1px solid var(--pg-border);
        border-radius: 0.7rem;
        min-width: 0;
    }

    .pg-admin-dash .pg-metric__top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        margin-bottom: 0.65rem;
    }

    .pg-admin-dash .pg-metric__label {
        margin: 0;
        font-size: 0.8125rem;
        font-weight: 650;
        color: var(--pg-muted);
    }

    .pg-admin-dash .pg-metric__value {
        margin: 0;
        font-size: 1.25rem;
        font-weight: 750;
        letter-spacing: -0.02em;
        color: var(--pg-navy);
        word-break: break-word;
    }

    .pg-admin-dash .pg-metric__hint {
        margin: 0.3rem 0 0;
        font-size: 0.75rem;
        color: #94A3B8;
    }

    /* Progress */
    .pg-admin-dash .pg-progress-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.75rem;
        margin-top: 0.875rem;
    }

    @media (min-width: 800px) {
        .pg-admin-dash .pg-progress-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }

    .pg-admin-dash .pg-progress {
        padding: 1rem 1.05rem;
        border: 1px solid var(--pg-border);
        border-radius: 0.7rem;
        background: #fff;
    }

    .pg-admin-dash .pg-progress__row {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 0.75rem;
        margin-bottom: 0.35rem;
    }

    .pg-admin-dash .pg-progress__label {
        margin: 0;
        font-size: 0.8125rem;
        font-weight: 700;
        color: var(--pg-navy);
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }

    .pg-admin-dash .pg-progress__pct {
        margin: 0;
        font-size: 0.9375rem;
        font-weight: 750;
        color: #0F766E;
        white-space: nowrap;
    }

    .pg-admin-dash .pg-progress__amounts {
        margin: 0 0 0.65rem;
        font-size: 0.8125rem;
        color: var(--pg-muted);
    }

    .pg-admin-dash .pg-progress__track {
        width: 100%;
        height: 0.5rem;
        border-radius: 9999px;
        background: #E2E8F0;
        overflow: hidden;
    }

    .pg-admin-dash .pg-progress__bar {
        height: 100%;
        border-radius: 9999px;
        background: linear-gradient(90deg, #0F766E, #14B8A6);
        transition: width 0.25s ease;
    }

    .pg-admin-dash .pg-progress__bar--blue {
        background: linear-gradient(90deg, #1D4ED8, #3B82F6);
    }

    /* Order status */
    .pg-admin-dash .pg-status-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.75rem;
    }

    @media (min-width: 960px) {
        .pg-admin-dash .pg-status-grid { grid-template-columns: repeat(5, minmax(0, 1fr)); }
    }

    .pg-admin-dash .pg-status {
        display: flex;
        flex-direction: column;
        gap: 0.65rem;
        min-height: 7rem;
        padding: 1rem;
        background: #fff;
        border: 1px solid var(--pg-border);
        border-radius: 0.75rem;
        text-decoration: none;
        transition: box-shadow 0.15s ease, border-color 0.15s ease, transform 0.15s ease;
        min-width: 0;
    }

    a.pg-admin-dash .pg-status:hover,
    .pg-admin-dash a.pg-status:hover {
        border-color: rgba(15, 118, 110, 0.3);
        box-shadow: 0 4px 14px rgba(15, 23, 42, 0.06);
        transform: translateY(-1px);
    }

    .pg-admin-dash .pg-status__label {
        margin: 0;
        font-size: 0.8125rem;
        font-weight: 650;
        color: #475569;
        line-height: 1.3;
    }

    .pg-admin-dash .pg-status__value {
        margin: auto 0 0;
        font-size: 1.75rem;
        font-weight: 750;
        letter-spacing: -0.03em;
        color: var(--pg-navy);
        line-height: 1;
    }

    /* Payment pipeline */
    .pg-admin-dash .pg-pipeline {
        display: flex;
        flex-wrap: wrap;
        align-items: stretch;
        gap: 0;
        border: 1px solid var(--pg-border);
        border-radius: 0.75rem;
        overflow: hidden;
        background: #FAFBFC;
    }

    .pg-admin-dash .pg-pipeline__step {
        flex: 1 1 9rem;
        min-width: 0;
        padding: 1rem 1.1rem;
        text-align: center;
        text-decoration: none;
        color: inherit;
        position: relative;
        border-right: 1px solid var(--pg-border);
        transition: background-color 0.15s ease;
    }

    .pg-admin-dash .pg-pipeline__step:last-child { border-right: 0; }

    .pg-admin-dash a.pg-pipeline__step:hover { background: #fff; }

    .pg-admin-dash .pg-pipeline__label {
        margin: 0.45rem 0 0;
        font-size: 0.75rem;
        font-weight: 650;
        color: var(--pg-muted);
        line-height: 1.35;
    }

    .pg-admin-dash .pg-pipeline__value {
        margin: 0.35rem 0 0;
        font-size: 1.5rem;
        font-weight: 750;
        color: var(--pg-navy);
        line-height: 1;
    }

    .pg-admin-dash .pg-pipeline__arrow {
        display: none;
    }

    @media (min-width: 900px) {
        .pg-admin-dash .pg-pipeline__arrow {
            display: block;
            position: absolute;
            right: -0.55rem;
            top: 50%;
            transform: translateY(-50%);
            z-index: 1;
            width: 1.1rem;
            height: 1.1rem;
            border-radius: 9999px;
            background: #fff;
            border: 1px solid var(--pg-border);
            color: #94A3B8;
            font-size: 0.65rem;
            line-height: 1.1rem;
            text-align: center;
        }
    }

    /* Quick actions compact */
    .pg-admin-dash .pg-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px solid var(--pg-border);
    }

    .pg-admin-dash .pg-action {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.5rem 0.8rem;
        border-radius: 0.55rem;
        border: 1px solid var(--pg-border);
        background: #fff;
        color: var(--pg-navy);
        font-size: 0.7875rem;
        font-weight: 650;
        text-decoration: none;
        transition: border-color 0.15s ease, background-color 0.15s ease;
    }

    .pg-admin-dash .pg-action:hover {
        border-color: rgba(15, 118, 110, 0.35);
        background: rgba(15, 118, 110, 0.04);
        color: #0F766E;
    }

    .pg-admin-dash .pg-action .pg-icon {
        width: 1.75rem;
        height: 1.75rem;
        border-radius: 0.45rem;
    }

    .pg-admin-dash .pg-action .pg-icon svg {
        width: 0.95rem !important;
        height: 0.95rem !important;
        max-width: 0.95rem !important;
        max-height: 0.95rem !important;
    }

    /* Employee table */
    .pg-admin-dash .pg-table-wrap {
        overflow-x: auto;
        border: 1px solid var(--pg-border);
        border-radius: 0.75rem;
        background: #fff;
    }

    .pg-admin-dash .pg-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 760px;
    }

    .pg-admin-dash .pg-table th {
        text-align: left;
        padding: 0.7rem 0.9rem;
        font-size: 0.6875rem;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: var(--pg-muted);
        background: #F8FAFC;
        border-bottom: 1px solid var(--pg-border);
        white-space: nowrap;
    }

    .pg-admin-dash .pg-table td {
        padding: 0.8rem 0.9rem;
        font-size: 0.8375rem;
        color: #334155;
        border-bottom: 1px solid #F1F5F9;
        vertical-align: middle;
    }

    .pg-admin-dash .pg-table tr:last-child td { border-bottom: 0; }
    .pg-admin-dash .pg-table tbody tr:hover td { background: #F8FAFC; }

    .pg-admin-dash .pg-emp-name {
        margin: 0;
        font-weight: 700;
        color: var(--pg-navy);
    }

    .pg-admin-dash .pg-emp-code {
        margin: 0.1rem 0 0;
        font-size: 0.6875rem;
        color: #94A3B8;
    }

    .pg-admin-dash .pg-role {
        display: inline-flex;
        padding: 0.12rem 0.5rem;
        border-radius: 9999px;
        background: #F1F5F9;
        color: #475569;
        font-size: 0.6875rem;
        font-weight: 650;
    }

    .pg-admin-dash .pg-mini-track {
        width: 5.5rem;
        height: 0.35rem;
        border-radius: 9999px;
        background: #E2E8F0;
        overflow: hidden;
        display: inline-block;
        vertical-align: middle;
        margin-right: 0.4rem;
    }

    .pg-admin-dash .pg-mini-bar {
        height: 100%;
        border-radius: 9999px;
        background: #0F766E;
    }

    .pg-admin-dash .pg-link {
        font-size: 0.75rem;
        font-weight: 700;
        color: #0F766E;
        text-decoration: none;
    }

    .pg-admin-dash .pg-link:hover { text-decoration: underline; }

    .pg-admin-dash .pg-num { font-variant-numeric: tabular-nums; text-align: right; white-space: nowrap; }
    .pg-admin-dash .pg-table th.pg-num { text-align: right; }

    .pg-admin-dash .pg-empty {
        margin: 0;
        padding: 1.5rem 0.75rem;
        text-align: center;
        font-size: 0.875rem;
        color: #94A3B8;
    }

    .pg-admin-dash .pg-top3 {
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px solid var(--pg-border);
    }

    .pg-admin-dash .pg-top3__title {
        margin: 0 0 0.65rem;
        font-size: 0.8125rem;
        font-weight: 700;
        color: var(--pg-navy);
    }

    .pg-admin-dash .pg-top3__row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        padding: 0.4rem 0;
        font-size: 0.8125rem;
    }

    .pg-admin-dash .pg-top3__rank {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 1.35rem;
        height: 1.35rem;
        margin-right: 0.5rem;
        border-radius: 9999px;
        background: rgba(15, 118, 110, 0.1);
        color: #0F766E;
        font-size: 0.6875rem;
        font-weight: 750;
    }

    .pg-admin-dash .pg-top3__amt {
        font-weight: 700;
        color: var(--pg-navy);
        font-variant-numeric: tabular-nums;
    }

    /* Soften Filament section chrome inside admin dash widgets */
    .fi-admin-director-business-performance-widget .fi-section,
    .fi-admin-director-order-overview-widget .fi-section,
    .fi-admin-director-payment-overview-widget .fi-section,
    .fi-admin-director-employee-performance-widget .fi-section,
    .fi-admin-director-recent-activity-widget .fi-section,
    .fi-admin-director-quick-actions-widget .fi-section {
        border: 1px solid #E2E8F0;
        border-radius: 0.75rem;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    }

    .fi-admin-director-business-performance-widget .fi-section-header,
    .fi-admin-director-order-overview-widget .fi-section-header,
    .fi-admin-director-payment-overview-widget .fi-section-header,
    .fi-admin-director-employee-performance-widget .fi-section-header,
    .fi-admin-director-recent-activity-widget .fi-section-header,
    .fi-admin-director-quick-actions-widget .fi-section-header {
        display: none;
    }

    .pg-admin-dash .manager-custom-period-row {
        margin-bottom: 1rem;
    }

    .pg-admin-dash .manager-period-action-btn--apply {
        border-color: #0F766E;
        background: #0F766E;
        color: #fff;
    }
</style>
