{{-- Scoped Admin Dashboard styles only. Do not size SVGs globally. --}}
<style>
    /* Page rhythm */
    .paramgold-admin-shell .fi-page-dashboard .fi-page-main,
    .paramgold-admin-shell .fi-main-ctn {
        gap: 0.875rem;
    }

    /* Sidebar polish (subtle, panel-wide) */
    .paramgold-admin-shell .fi-sidebar-item-button {
        border-radius: 0.5rem;
        transition: background-color 0.15s ease, color 0.15s ease;
    }

    .paramgold-admin-shell .fi-sidebar-item-button:hover {
        background: rgba(15, 118, 110, 0.08);
    }

    .paramgold-admin-shell .fi-sidebar-item-button .fi-icon,
    .paramgold-admin-shell .fi-sidebar-item-button svg {
        width: 1.125rem !important;
        height: 1.125rem !important;
        max-width: 1.125rem;
        max-height: 1.125rem;
    }

    .paramgold-admin-shell .fi-active > .fi-sidebar-item-button,
    .paramgold-admin-shell .fi-sidebar-item-button[aria-current="page"] {
        background: rgba(15, 118, 110, 0.12);
        color: rgb(15 118 110);
        font-weight: 650;
    }

    .paramgold-admin-shell .fi-sidebar-group-label {
        letter-spacing: 0.04em;
        font-size: 0.6875rem;
        text-transform: uppercase;
        color: rgb(100 116 139);
        margin-top: 0.5rem;
    }

    /* Topbar polish */
    .paramgold-admin-shell .fi-topbar {
        border-bottom: 1px solid rgb(226 232 240);
        background: rgba(255, 255, 255, 0.92);
        backdrop-filter: blur(8px);
    }

    /* ===== Dashboard primitives ===== */
    .pg-dash-card {
        border: 1px solid rgb(226 232 240);
        border-radius: 0.75rem;
        background: #fff;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    }

    .pg-dash-section {
        padding: 1rem 1.125rem 1.125rem;
    }

    .pg-dash-section__head {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.75rem;
        margin-bottom: 1rem;
    }

    .pg-dash-section__title {
        margin: 0;
        font-size: 0.9375rem;
        font-weight: 750;
        letter-spacing: -0.01em;
        color: rgb(15 23 42);
    }

    .pg-dash-section__subtitle {
        margin: 0.2rem 0 0;
        font-size: 0.75rem;
        color: rgb(100 116 139);
    }

    .pg-dash-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 2rem;
        height: 2rem;
        flex-shrink: 0;
        border-radius: 0.55rem;
    }

    .pg-dash-icon svg,
    .pg-dash-icon .pg-dash-icon__glyph {
        width: 1rem !important;
        height: 1rem !important;
        max-width: 1rem;
        max-height: 1rem;
        display: block;
    }

    .pg-dash-icon--teal { background: rgba(15, 118, 110, 0.12); color: rgb(15 118 110); }
    .pg-dash-icon--green { background: rgba(22, 163, 74, 0.12); color: rgb(21 128 61); }
    .pg-dash-icon--amber { background: rgba(245, 158, 11, 0.14); color: rgb(180 83 9); }
    .pg-dash-icon--blue { background: rgba(37, 99, 235, 0.12); color: rgb(29 78 216); }
    .pg-dash-icon--red { background: rgba(239, 68, 68, 0.12); color: rgb(185 28 28); }
    .pg-dash-icon--slate { background: rgb(241 245 249); color: rgb(71 85 105); }

    /* Welcome */
    .pg-dash-welcome {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 1rem 1.25rem;
        border: 1px solid rgb(226 232 240);
        border-radius: 0.75rem;
        background: #fff;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    }

    .pg-dash-welcome__eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        margin: 0 0 0.35rem;
        padding: 0.15rem 0.5rem;
        border-radius: 9999px;
        background: rgba(15, 118, 110, 0.1);
        color: rgb(15 118 110);
        font-size: 0.6875rem;
        font-weight: 700;
        letter-spacing: 0.03em;
        text-transform: uppercase;
    }

    .pg-dash-welcome__eyebrow-dot {
        width: 0.4rem;
        height: 0.4rem;
        border-radius: 9999px;
        background: rgb(16 185 129);
        box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2);
    }

    .pg-dash-welcome__title {
        margin: 0;
        font-size: 1.25rem;
        font-weight: 800;
        letter-spacing: -0.02em;
        color: rgb(15 23 42);
        line-height: 1.25;
    }

    .pg-dash-welcome__product {
        margin: 0.25rem 0 0;
        font-size: 0.8125rem;
        font-weight: 650;
        color: rgb(15 118 110);
    }

    .pg-dash-welcome__date {
        margin: 0.15rem 0 0;
        font-size: 0.75rem;
        color: rgb(100 116 139);
    }

    .pg-dash-welcome__avatar {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 2.75rem;
        height: 2.75rem;
        border-radius: 9999px;
        background: linear-gradient(135deg, #0F766E, #14B8A6);
        color: #fff;
        font-size: 0.9375rem;
        font-weight: 800;
        flex-shrink: 0;
    }

    /* KPI row */
    .pg-dash-kpi-grid {
        display: grid;
        grid-template-columns: repeat(1, minmax(0, 1fr));
        gap: 0.75rem;
        margin-top: 0.75rem;
    }

    @media (min-width: 640px) {
        .pg-dash-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }

    @media (min-width: 1100px) {
        .pg-dash-kpi-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    }

    .pg-dash-kpi {
        display: flex;
        align-items: flex-start;
        gap: 0.75rem;
        padding: 0.875rem 1rem;
        border: 1px solid rgb(226 232 240);
        border-radius: 0.75rem;
        background: #fff;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        min-width: 0;
    }

    .pg-dash-kpi__body { min-width: 0; flex: 1; }
    .pg-dash-kpi__label {
        margin: 0;
        font-size: 0.75rem;
        font-weight: 600;
        color: rgb(100 116 139);
    }
    .pg-dash-kpi__value {
        margin: 0.2rem 0 0;
        font-size: 1.125rem;
        font-weight: 800;
        letter-spacing: -0.02em;
        color: rgb(15 23 42);
        line-height: 1.2;
        word-break: break-word;
    }
    .pg-dash-kpi__meta {
        margin: 0.2rem 0 0;
        font-size: 0.6875rem;
        color: rgb(148 163 184);
    }

    /* Team snapshot */
    .pg-dash-team-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.625rem;
        margin-top: 0.75rem;
    }

    @media (min-width: 768px) {
        .pg-dash-team-grid { grid-template-columns: repeat(5, minmax(0, 1fr)); }
    }

    .pg-dash-team-chip {
        padding: 0.75rem 0.875rem;
        border: 1px solid rgb(226 232 240);
        border-radius: 0.7rem;
        background: #fff;
        min-width: 0;
    }

    .pg-dash-team-chip__label {
        margin: 0;
        font-size: 0.6875rem;
        font-weight: 650;
        color: rgb(100 116 139);
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }

    .pg-dash-team-chip__value {
        margin: 0.25rem 0 0;
        font-size: 1.25rem;
        font-weight: 800;
        color: rgb(15 23 42);
        line-height: 1;
    }

    /* Metric cards */
    .pg-dash-metric-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr);
        gap: 0.75rem;
    }

    @media (min-width: 640px) {
        .pg-dash-metric-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }

    @media (min-width: 1024px) {
        .pg-dash-metric-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    }

    .pg-dash-metric {
        display: flex;
        flex-direction: column;
        gap: 0.65rem;
        padding: 0.9rem 1rem;
        border: 1px solid rgb(226 232 240);
        border-radius: 0.75rem;
        background: #fff;
        min-width: 0;
    }

    .pg-dash-metric__top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
    }

    .pg-dash-metric__label {
        margin: 0;
        font-size: 0.75rem;
        font-weight: 650;
        color: rgb(100 116 139);
    }

    .pg-dash-metric__value {
        margin: 0;
        font-size: 1.2rem;
        font-weight: 800;
        letter-spacing: -0.02em;
        color: rgb(15 23 42);
        word-break: break-word;
    }

    .pg-dash-metric__hint {
        margin: 0;
        font-size: 0.6875rem;
        color: rgb(148 163 184);
    }

    /* Progress */
    .pg-dash-progress-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr);
        gap: 0.75rem;
        margin-top: 0.75rem;
    }

    @media (min-width: 768px) {
        .pg-dash-progress-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }

    .pg-dash-progress {
        padding: 0.9rem 1rem;
        border: 1px solid rgb(226 232 240);
        border-radius: 0.75rem;
        background: rgb(248 250 252);
    }

    .pg-dash-progress__row {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 0.75rem;
        margin-bottom: 0.35rem;
    }

    .pg-dash-progress__label {
        margin: 0;
        font-size: 0.8125rem;
        font-weight: 700;
        color: rgb(30 41 59);
    }

    .pg-dash-progress__pct {
        margin: 0;
        font-size: 0.875rem;
        font-weight: 800;
        color: rgb(15 118 110);
        white-space: nowrap;
    }

    .pg-dash-progress__amounts {
        margin: 0 0 0.55rem;
        font-size: 0.75rem;
        color: rgb(100 116 139);
    }

    .pg-dash-progress__track {
        width: 100%;
        height: 0.55rem;
        overflow: hidden;
        border-radius: 9999px;
        background: rgb(226 232 240);
    }

    .pg-dash-progress__bar {
        height: 100%;
        border-radius: 9999px;
        background: linear-gradient(90deg, #0F766E, #14B8A6);
    }

    .pg-dash-progress__bar--collection {
        background: linear-gradient(90deg, #2563EB, #60A5FA);
    }

    /* Status cards */
    .pg-dash-status-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.75rem;
    }

    @media (min-width: 900px) {
        .pg-dash-status-grid { grid-template-columns: repeat(5, minmax(0, 1fr)); }
        .pg-dash-status-grid--4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    }

    .pg-dash-status {
        display: flex;
        flex-direction: column;
        gap: 0.65rem;
        min-height: 6.25rem;
        padding: 0.9rem 1rem;
        border: 1px solid rgb(226 232 240);
        border-radius: 0.75rem;
        background: #fff;
        text-decoration: none;
        transition: border-color 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
        min-width: 0;
    }

    a.pg-dash-status:hover {
        border-color: rgba(15, 118, 110, 0.35);
        box-shadow: 0 4px 14px rgba(15, 23, 42, 0.06);
        transform: translateY(-1px);
    }

    .pg-dash-status__label {
        margin: 0;
        font-size: 0.75rem;
        font-weight: 650;
        color: rgb(71 85 105);
        line-height: 1.3;
    }

    .pg-dash-status__value {
        margin: auto 0 0;
        font-size: 1.75rem;
        font-weight: 800;
        letter-spacing: -0.03em;
        color: rgb(15 23 42);
        line-height: 1;
    }

    .pg-dash-status--amber { border-left: 3px solid rgb(245 158 11); }
    .pg-dash-status--green { border-left: 3px solid rgb(34 197 94); }
    .pg-dash-status--blue { border-left: 3px solid rgb(59 130 246); }
    .pg-dash-status--teal { border-left: 3px solid rgb(20 184 166); }
    .pg-dash-status--red { border-left: 3px solid rgb(239 68 68); }

    /* Segmented filters */
    .pg-dash-seg {
        display: inline-flex;
        flex-wrap: wrap;
        gap: 0.25rem;
        padding: 0.2rem;
        border: 1px solid rgb(226 232 240);
        border-radius: 0.65rem;
        background: rgb(248 250 252);
    }

    .pg-dash-seg__btn {
        border: 0;
        border-radius: 0.5rem;
        background: transparent;
        padding: 0.4rem 0.7rem;
        font-size: 0.75rem;
        font-weight: 650;
        color: rgb(71 85 105);
        cursor: pointer;
        line-height: 1.2;
    }

    .pg-dash-seg__btn:hover { color: rgb(15 118 110); }

    .pg-dash-seg__btn--active {
        background: #fff;
        color: rgb(15 118 110);
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.08);
    }

    /* Employee table */
    .pg-dash-table-wrap {
        overflow-x: auto;
        border: 1px solid rgb(226 232 240);
        border-radius: 0.75rem;
        background: #fff;
    }

    .pg-dash-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 720px;
    }

    .pg-dash-table th {
        text-align: left;
        padding: 0.7rem 0.85rem;
        font-size: 0.6875rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: rgb(100 116 139);
        background: rgb(248 250 252);
        border-bottom: 1px solid rgb(226 232 240);
        white-space: nowrap;
    }

    .pg-dash-table td {
        padding: 0.75rem 0.85rem;
        font-size: 0.8125rem;
        color: rgb(30 41 59);
        border-bottom: 1px solid rgb(241 245 249);
        vertical-align: middle;
    }

    .pg-dash-table tr:last-child td { border-bottom: 0; }

    .pg-dash-emp-name {
        margin: 0;
        font-weight: 700;
        color: rgb(15 23 42);
    }

    .pg-dash-emp-code {
        margin: 0.1rem 0 0;
        font-size: 0.6875rem;
        color: rgb(148 163 184);
    }

    .pg-dash-pill {
        display: inline-flex;
        align-items: center;
        border-radius: 9999px;
        padding: 0.15rem 0.5rem;
        font-size: 0.6875rem;
        font-weight: 700;
        white-space: nowrap;
    }

    .pg-dash-pill--good { background: rgba(22, 163, 74, 0.12); color: rgb(21 128 61); }
    .pg-dash-pill--warn { background: rgba(245, 158, 11, 0.14); color: rgb(180 83 9); }
    .pg-dash-pill--neutral { background: rgb(241 245 249); color: rgb(71 85 105); }

    .pg-dash-link {
        font-size: 0.75rem;
        font-weight: 700;
        color: rgb(15 118 110);
        text-decoration: none;
        white-space: nowrap;
    }

    .pg-dash-link:hover { text-decoration: underline; }

    /* Quick actions */
    .pg-dash-actions {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.65rem;
    }

    @media (min-width: 768px) {
        .pg-dash-actions { grid-template-columns: repeat(5, minmax(0, 1fr)); }
    }

    .pg-dash-action {
        display: flex;
        align-items: center;
        gap: 0.55rem;
        min-height: 2.75rem;
        padding: 0.65rem 0.8rem;
        border: 1px solid rgb(226 232 240);
        border-radius: 0.7rem;
        background: #fff;
        text-decoration: none;
        color: rgb(30 41 59);
        font-size: 0.7875rem;
        font-weight: 650;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }

    .pg-dash-action:hover {
        border-color: rgba(15, 118, 110, 0.35);
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.05);
    }

    /* Activity */
    .pg-dash-activity-item {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 0.75rem;
        align-items: center;
        padding: 0.75rem 0.85rem;
        border: 1px solid rgb(226 232 240);
        border-radius: 0.65rem;
        background: #fff;
    }

    .pg-dash-activity-item + .pg-dash-activity-item { margin-top: 0.5rem; }

    .pg-dash-empty {
        margin: 0;
        padding: 1.25rem 0.5rem;
        text-align: center;
        font-size: 0.8125rem;
        color: rgb(148 163 184);
    }

    /* Hide default Filament section chrome when we use custom cards */
    .fi-admin-director-welcome-widget > .fi-wi-widget-content,
    .fi-admin-director-business-performance-widget .fi-section-content-ctn,
    .fi-admin-director-order-overview-widget .fi-section-content-ctn,
    .fi-admin-director-payment-overview-widget .fi-section-content-ctn,
    .fi-admin-director-employee-performance-widget .fi-section-content-ctn,
    .fi-admin-director-recent-activity-widget .fi-section-content-ctn,
    .fi-admin-director-quick-actions-widget .fi-section-content-ctn,
    .fi-admin-director-team-snapshot-widget .fi-section-content-ctn {
        /* keep default */
    }

    .fi-admin-director-business-performance-widget .fi-section,
    .fi-admin-director-order-overview-widget .fi-section,
    .fi-admin-director-payment-overview-widget .fi-section,
    .fi-admin-director-employee-performance-widget .fi-section,
    .fi-admin-director-recent-activity-widget .fi-section,
    .fi-admin-director-quick-actions-widget .fi-section,
    .fi-admin-director-team-snapshot-widget .fi-section {
        border: 1px solid rgb(226 232 240);
        border-radius: 0.75rem;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    }
</style>
