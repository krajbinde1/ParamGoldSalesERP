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
        .pg-admin-dash .pg-kpi-grid--6 { grid-template-columns: repeat(6, minmax(0, 1fr)); }
    }

    @media (min-width: 720px) and (max-width: 1119px) {
        .pg-admin-dash .pg-kpi-grid--6 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    }

    .pg-admin-dash .pg-kpi {
        display: flex;
        align-items: flex-start;
        gap: 0.85rem;
        padding: 1rem 1.05rem;
        transition: box-shadow 0.15s ease, border-color 0.15s ease;
    }

    .pg-admin-dash .pg-kpi--alert {
        border-color: rgba(220, 38, 38, 0.35);
        background: #FEF2F2;
    }

    .pg-admin-dash a.pg-kpi {
        text-decoration: none;
        color: inherit;
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

    @media (min-width: 720px) {
        .pg-admin-dash .pg-team__strip {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    @media (min-width: 1100px) {
        .pg-admin-dash .pg-team__strip {
            grid-template-columns: repeat(6, minmax(0, 1fr));
        }
    }

    .pg-admin-dash .pg-team__cell {
        display: flex;
        align-items: center;
        gap: 0.7rem;
        padding: 0.85rem 1rem;
        min-width: 0;
        border-bottom: 1px solid var(--pg-border);
        text-decoration: none;
        color: inherit;
        cursor: pointer;
        transition: background-color 0.15s ease, box-shadow 0.15s ease;
    }

    .pg-admin-dash a.pg-team__cell:hover {
        background: #fff;
        box-shadow: inset 0 0 0 1px rgba(15, 118, 110, 0.18);
    }

    .pg-admin-dash a.pg-team__cell:focus-visible {
        outline: 2px solid rgba(15, 118, 110, 0.45);
        outline-offset: -2px;
        z-index: 1;
    }

    @media (max-width: 719px) {
        .pg-admin-dash .pg-team__cell:nth-child(odd) { border-right: 1px solid var(--pg-border); }
        .pg-admin-dash .pg-team__cell:nth-last-child(-n+2) { border-bottom: 0; }
    }

    @media (min-width: 720px) and (max-width: 1099px) {
        .pg-admin-dash .pg-team__cell:not(:nth-child(3n)) { border-right: 1px solid var(--pg-border); }
        .pg-admin-dash .pg-team__cell:nth-last-child(-n+3) { border-bottom: 0; }
    }

    @media (min-width: 1100px) {
        .pg-admin-dash .pg-team__cell {
            border-bottom: 0;
            border-right: 1px solid var(--pg-border);
            padding: 0.85rem 0.75rem;
        }
        .pg-admin-dash .pg-team__cell:last-child { border-right: 0; }
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
        padding: 0.4rem 0.55rem;
        font-size: 0.72rem;
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
        display: block;
        text-decoration: none;
        color: inherit;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }

    .pg-admin-dash a.pg-progress:hover {
        border-color: rgba(15, 118, 110, 0.28);
        box-shadow: 0 4px 14px rgba(15, 23, 42, 0.05);
    }

    .pg-admin-dash .pg-progress__remain {
        margin: 0.55rem 0 0;
        font-size: 0.75rem;
        font-weight: 650;
        color: var(--pg-muted);
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

    .pg-admin-dash .pg-progress__bar--field {
        background: linear-gradient(90deg, #7C3AED, #A78BFA);
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

    /* Compact Payment Approval cards — match Collection card density */
    .pg-admin-dash .pg-status--compact {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr);
        grid-template-rows: auto auto auto;
        column-gap: 0.7rem;
        row-gap: 0.12rem;
        align-items: center;
        min-height: 0;
        padding: 0.75rem 0.9rem;
    }

    .pg-admin-dash .pg-status--compact .pg-icon {
        grid-row: 1 / span 3;
        width: 1.85rem;
        height: 1.85rem;
        border-radius: 0.5rem;
        align-self: center;
    }

    .pg-admin-dash .pg-status--compact .pg-icon svg,
    .pg-admin-dash .pg-status--compact .pg-icon .fi-icon {
        width: 0.95rem !important;
        height: 0.95rem !important;
        max-width: 0.95rem !important;
        max-height: 0.95rem !important;
    }

    .pg-admin-dash .pg-status--compact .pg-status__label {
        font-size: 0.75rem;
    }

    .pg-admin-dash .pg-status--compact .pg-status__value {
        margin: 0;
        font-size: 1.35rem;
    }

    .pg-admin-dash .pg-status--compact .pg-kpi__meta {
        margin: 0;
        font-size: 0.72rem;
    }

    .pg-payment-overview .pg-section-head {
        margin-bottom: 0.7rem;
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
    .fi-admin-director-attention-widget .fi-section,
    .fi-admin-director-team-activity-widget .fi-section,
    .fi-admin-director-collection-outstanding-widget .fi-section,
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
    .fi-admin-director-attention-widget .fi-section-header,
    .fi-admin-director-team-activity-widget .fi-section-header,
    .fi-admin-director-collection-outstanding-widget .fi-section-header,
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

    .pg-admin-dash .pg-attention {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.55rem;
    }

    @media (min-width: 720px) {
        .pg-admin-dash .pg-attention { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }

    @media (min-width: 1120px) {
        .pg-admin-dash .pg-attention { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    }

    .pg-admin-dash .pg-attention__item {
        display: flex;
        align-items: center;
        gap: 0.65rem;
        padding: 0.75rem 0.9rem;
        border: 1px solid var(--pg-border);
        border-radius: 0.65rem;
        background: #fff;
        text-decoration: none;
        color: var(--pg-navy);
        font-size: 0.875rem;
        font-weight: 650;
    }

    .pg-admin-dash .pg-attention__item:hover {
        border-color: rgba(15, 118, 110, 0.3);
    }

    .pg-admin-dash .pg-attention__dot {
        width: 0.55rem;
        height: 0.55rem;
        border-radius: 999px;
        flex-shrink: 0;
        background: #94A3B8;
    }

    .pg-admin-dash .pg-attention__item--red {
        border-color: rgba(220, 38, 38, 0.22);
        background: #FEF2F2;
    }
    .pg-admin-dash .pg-attention__item--red .pg-attention__dot { background: #DC2626; }

    .pg-admin-dash .pg-attention__item--orange {
        border-color: rgba(217, 119, 6, 0.25);
        background: #FFFBEB;
    }
    .pg-admin-dash .pg-attention__item--orange .pg-attention__dot { background: #D97706; }

    .pg-admin-dash .pg-attention__item--green {
        border-color: rgba(22, 163, 74, 0.2);
        background: #F0FDF4;
        color: #166534;
    }
    .pg-admin-dash .pg-attention__item--green .pg-attention__dot { background: #16A34A; }

    .pg-admin-dash .pg-flow {
        display: flex;
        flex-wrap: wrap;
        align-items: stretch;
        gap: 0.5rem;
    }

    .pg-admin-dash .pg-flow__stage {
        flex: 1 1 7.5rem;
        min-width: 7rem;
        padding: 0.85rem 0.9rem;
        border: 1px solid var(--pg-border);
        border-radius: 0.65rem;
        text-align: center;
        text-decoration: none;
        color: inherit;
        background: #fff;
    }

    .pg-admin-dash .pg-flow__stage:hover {
        border-color: rgba(15, 118, 110, 0.3);
    }

    .pg-admin-dash .pg-flow__stage--stuck {
        border-color: rgba(217, 119, 6, 0.4);
        background: #FFFBEB;
    }

    .pg-admin-dash .pg-flow__stage--rejected {
        flex: 0 1 7rem;
        opacity: 0.9;
    }

    .pg-admin-dash .pg-flow__label {
        margin: 0;
        font-size: 0.7rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: var(--pg-muted);
    }

    .pg-admin-dash .pg-flow__value {
        margin: 0.35rem 0 0;
        font-size: 1.35rem;
        font-weight: 750;
        color: var(--pg-navy);
    }

    .pg-admin-dash .pg-flow__arrow {
        align-self: center;
        color: #94A3B8;
        font-weight: 700;
    }

    @media (max-width: 719px) {
        .pg-admin-dash .pg-flow__arrow { display: none; }
    }

    .pg-admin-dash .pg-delay {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-top: 0.85rem;
    }

    .pg-admin-dash .pg-delay__item {
        padding: 0.4rem 0.7rem;
        border-radius: 999px;
        background: #FFFBEB;
        border: 1px solid rgba(217, 119, 6, 0.25);
        color: #92400E;
        font-size: 0.75rem;
        font-weight: 650;
        text-decoration: none;
    }

    .pg-admin-dash .pg-split {
        display: grid;
        grid-template-columns: 1fr;
        gap: 1rem;
    }

    @media (min-width: 800px) {
        .pg-admin-dash .pg-split { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }

    .pg-admin-dash .pg-split__title {
        margin: 0 0 0.65rem;
        font-size: 0.75rem;
        font-weight: 750;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: var(--pg-muted);
    }

    .pg-admin-dash .pg-person {
        display: flex;
        justify-content: space-between;
        gap: 0.75rem;
        padding: 0.55rem 0;
        border-bottom: 1px solid var(--pg-border);
        text-decoration: none;
        color: inherit;
    }

    .pg-admin-dash .pg-person:last-child { border-bottom: 0; }

    .pg-admin-dash .pg-person__name {
        font-weight: 650;
        color: var(--pg-navy);
    }

    .pg-admin-dash .pg-person__pct {
        font-variant-numeric: tabular-nums;
        font-weight: 750;
    }

    .pg-admin-dash .pg-person__pct--good { color: #15803D; }
    .pg-admin-dash .pg-person__pct--warn { color: #B45309; }

    .pg-admin-dash .pg-activity {
        list-style: none;
        margin: 0;
        padding: 0;
    }

    .pg-admin-dash .pg-activity__item {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        padding: 0.7rem 0;
        border-bottom: 1px solid var(--pg-border);
    }

    .pg-admin-dash .pg-activity__item:last-child { border-bottom: 0; }

    .pg-admin-dash .pg-activity__text {
        margin: 0;
        font-weight: 650;
        color: var(--pg-navy);
    }

    .pg-admin-dash .pg-activity__meta,
    .pg-admin-dash .pg-activity__when {
        margin: 0.15rem 0 0;
        font-size: 0.75rem;
        color: var(--pg-muted);
        white-space: nowrap;
    }

    .pg-admin-dash .pg-status--alert {
        border-color: rgba(220, 38, 38, 0.3);
        background: #FEF2F2;
    }

    .pg-admin-dash a.pg-status {
        text-decoration: none;
        color: inherit;
    }

    @media (min-width: 800px) {
        .pg-admin-dash .pg-status-grid--3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .pg-admin-dash .pg-status-grid--4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    }

    .pg-admin-dash .pg-team-card {
        padding: 0.85rem 0;
        border-bottom: 1px solid var(--pg-border);
    }

    .pg-admin-dash .pg-team-card:last-of-type { border-bottom: 0; }

    .pg-admin-dash .pg-team-card__head {
        display: flex;
        align-items: center;
        gap: 0.65rem 0.85rem;
        flex-wrap: wrap;
        margin-bottom: 0.65rem;
    }

    .pg-admin-dash .pg-team-card__name {
        font-size: 0.9375rem;
        font-weight: 700;
        color: var(--pg-navy);
        text-decoration: none;
    }

    .pg-admin-dash .pg-team-card__name:hover { color: #0F766E; }

    .pg-admin-dash .pg-team-card__overall {
        margin-left: auto;
        font-size: 0.75rem;
        font-weight: 750;
        font-variant-numeric: tabular-nums;
        color: var(--pg-navy);
        background: #F1F5F9;
        border-radius: 9999px;
        padding: 0.2rem 0.55rem;
        white-space: nowrap;
    }

    .pg-admin-dash .pg-team-card__overall--good {
        color: #15803D;
        background: #DCFCE7;
    }

    .pg-admin-dash .pg-team-card__overall--warn {
        color: #B45309;
        background: #FEF3C7;
    }

    .pg-admin-dash .pg-team-card__wa {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0.28rem 0.6rem;
        border-radius: 0.45rem;
        background: #25D366;
        color: #fff;
        font-size: 0.6875rem;
        font-weight: 700;
        letter-spacing: 0.01em;
        text-decoration: none;
        white-space: nowrap;
    }

    .pg-admin-dash .pg-team-card__wa:hover { background: #1EBE5A; }

    .pg-admin-dash .pg-team-card__metrics {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.65rem;
    }

    @media (min-width: 800px) {
        .pg-admin-dash .pg-team-card__metrics {
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.85rem 1.25rem;
        }
    }

    .pg-admin-dash .pg-team-metric__row {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 0.5rem;
        margin-bottom: 0.15rem;
    }

    .pg-admin-dash .pg-team-metric__label {
        font-size: 0.6875rem;
        font-weight: 750;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: var(--pg-muted);
    }

    .pg-admin-dash .pg-team-metric__pct {
        font-size: 0.8125rem;
        font-weight: 750;
        font-variant-numeric: tabular-nums;
        color: var(--pg-navy);
        white-space: nowrap;
    }

    .pg-admin-dash .pg-team-metric__values {
        margin: 0 0 0.35rem;
        font-size: 0.75rem;
        color: var(--pg-muted);
        font-variant-numeric: tabular-nums;
    }

    .pg-admin-dash button.pg-team-metric {
        display: block;
        width: 100%;
        text-align: left;
        background: transparent;
        border: 1px solid transparent;
        border-radius: 0.5rem;
        padding: 0.4rem 0.5rem;
        cursor: pointer;
        color: inherit;
        font: inherit;
    }

    .pg-admin-dash button.pg-team-metric:hover {
        background: #F8FAFC;
        border-color: var(--pg-border);
    }

    .pg-admin-dash button.pg-team-metric--active {
        background: #F0FDFA;
        border-color: #99F6E4;
    }

    .pg-admin-dash .pg-team-detail {
        margin-top: 0.75rem;
        padding: 0.7rem 0.75rem 0.55rem;
        border: 1px solid var(--pg-border);
        border-radius: 0.65rem;
        background: #F8FAFC;
    }

    .pg-admin-dash .pg-team-detail__head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        margin-bottom: 0.55rem;
    }

    .pg-admin-dash .pg-team-detail__title {
        margin: 0;
        font-size: 0.8125rem;
        font-weight: 700;
        color: var(--pg-navy);
    }

    .pg-admin-dash .pg-team-detail__close {
        border: 0;
        background: transparent;
        color: var(--pg-muted);
        font-size: 0.75rem;
        font-weight: 650;
        cursor: pointer;
        padding: 0.15rem 0.2rem;
    }

    .pg-admin-dash .pg-team-detail__close:hover {
        color: var(--pg-navy);
    }

    .pg-admin-dash .pg-team-detail__table-wrap {
        overflow-x: auto;
    }

    .pg-admin-dash .pg-team-detail__table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.75rem;
        color: var(--pg-navy);
    }

    .pg-admin-dash .pg-team-detail__table th {
        text-align: left;
        font-size: 0.6875rem;
        font-weight: 750;
        letter-spacing: 0.03em;
        text-transform: uppercase;
        color: var(--pg-muted);
        padding: 0.35rem 0.5rem 0.45rem;
        border-bottom: 1px solid var(--pg-border);
        white-space: nowrap;
    }

    .pg-admin-dash .pg-team-detail__table td {
        padding: 0.4rem 0.5rem;
        border-bottom: 1px solid #E2E8F0;
        vertical-align: top;
    }

    .pg-admin-dash .pg-team-detail__table tr:last-child td {
        border-bottom: 0;
    }

    .pg-admin-dash .pg-team-detail__table a {
        color: #0F766E;
        font-weight: 650;
        text-decoration: none;
    }

    .pg-admin-dash .pg-team-detail__table a:hover {
        text-decoration: underline;
    }

    .pg-admin-dash .pg-team-detail__num {
        text-align: right;
        font-variant-numeric: tabular-nums;
        white-space: nowrap;
    }

    .pg-admin-dash .pg-team-detail__table tfoot th,
    .pg-admin-dash .pg-team-detail__table tfoot td {
        padding-top: 0.55rem;
        border-bottom: 0;
        border-top: 1px solid var(--pg-border);
        font-weight: 750;
        color: var(--pg-navy);
        background: #fff;
    }

    @media (max-width: 799px) {
        .pg-admin-dash .pg-team-card__overall {
            margin-left: 0;
        }
    }
</style>
