@include('filament.widgets.partials.manager-dashboard-styles')

<style>
    .paramgold-admin-shell .fi-main {
        background: rgb(248 250 252);
    }

    .dark .paramgold-admin-shell .fi-main {
        background: rgb(15 23 42);
    }

    .paramgold-admin-shell .fi-section,
    .paramgold-admin-shell .fi-wi-widget .fi-section {
        border-radius: 0.875rem;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    }

    .paramgold-admin-shell .fi-ta-header-cell {
        font-weight: 600;
    }

    .paramgold-admin-shell .fi-ta-ctn {
        overflow-x: auto;
    }

    .paramgold-admin-shell .fi-page-main {
        gap: 1rem;
    }

    .paramgold-welcome-card {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        border: 1px solid rgb(229 231 235);
        border-radius: 0.875rem;
        background: linear-gradient(135deg, rgb(255 255 255), rgb(249 250 251));
        padding: 1.25rem 1.5rem;
    }

    .dark .paramgold-welcome-card {
        border-color: rgb(55 65 81);
        background: linear-gradient(135deg, rgb(17 24 39), rgb(31 41 55));
    }

    .paramgold-welcome-card__title {
        margin: 0;
        font-size: 1.5rem;
        font-weight: 700;
        color: rgb(17 24 39);
    }

    .dark .paramgold-welcome-card__title {
        color: rgb(255 255 255);
    }

    .paramgold-welcome-card__meta,
    .paramgold-welcome-card__subtitle {
        margin: 0.25rem 0 0;
        font-size: 0.875rem;
        color: rgb(107 114 128);
    }

    .paramgold-welcome-card__avatar {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 3.5rem;
        height: 3.5rem;
        border-radius: 9999px;
        background: rgb(220 252 231);
        color: rgb(21 128 61);
        font-size: 1.125rem;
        font-weight: 700;
    }

    .paramgold-summary-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr);
        gap: 0.875rem;
    }

    @media (min-width: 640px) {
        .paramgold-summary-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (min-width: 1024px) {
        .paramgold-summary-grid {
            grid-template-columns: repeat(5, minmax(0, 1fr));
        }
    }

    .paramgold-summary-card {
        display: flex;
        min-height: 6.5rem;
        flex-direction: column;
        justify-content: space-between;
        border: 1px solid rgb(229 231 235);
        border-radius: 0.75rem;
        background: rgb(255 255 255);
        padding: 0.875rem 1rem;
        text-decoration: none;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }

    .dark .paramgold-summary-card {
        border-color: rgb(55 65 81);
        background: rgb(17 24 39);
    }

    .paramgold-summary-card:hover {
        box-shadow: 0 4px 14px rgba(15, 23, 42, 0.08);
    }

    .paramgold-summary-card--warning { border-top: 3px solid rgb(245 158 11); }
    .paramgold-summary-card--success { border-top: 3px solid rgb(34 197 94); }
    .paramgold-summary-card--info { border-top: 3px solid rgb(59 130 246); }
    .paramgold-summary-card--danger { border-top: 3px solid rgb(239 68 68); }
    .paramgold-summary-card--primary { border-top: 3px solid rgb(217 119 6); }

    .paramgold-summary-card__label {
        margin: 0;
        font-size: 0.75rem;
        font-weight: 600;
        color: rgb(75 85 99);
    }

    .paramgold-summary-card__value {
        margin: 0.5rem 0 0;
        font-size: 1.5rem;
        font-weight: 700;
        color: rgb(17 24 39);
    }

    .dark .paramgold-summary-card__value {
        color: rgb(255 255 255);
    }

    .paramgold-quick-actions-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.75rem;
    }

    @media (min-width: 768px) {
        .paramgold-quick-actions-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }
    }

    .paramgold-quick-action {
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 2.75rem;
        border-radius: 0.625rem;
        padding: 0.625rem 0.875rem;
        font-size: 0.8125rem;
        font-weight: 600;
        text-decoration: none;
        text-align: center;
    }

    .paramgold-quick-action--success { background: rgb(220 252 231); color: rgb(21 128 61); }
    .paramgold-quick-action--info { background: rgb(219 234 254); color: rgb(29 78 216); }
    .paramgold-quick-action--primary { background: rgb(254 243 199); color: rgb(180 83 9); }
    .paramgold-quick-action--warning { background: rgb(255 237 213); color: rgb(194 65 12); }

    .paramgold-activity-tabs {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-bottom: 1rem;
    }

    .paramgold-activity-list {
        display: flex;
        flex-direction: column;
        gap: 0.625rem;
    }

    .paramgold-activity-item {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 0.75rem;
        align-items: center;
        border: 1px solid rgb(229 231 235);
        border-radius: 0.625rem;
        padding: 0.75rem 0.875rem;
        background: rgb(255 255 255);
    }

    .dark .paramgold-activity-item {
        border-color: rgb(55 65 81);
        background: rgb(17 24 39);
    }

    .paramgold-activity-item__title {
        margin: 0;
        font-size: 0.875rem;
        font-weight: 600;
        color: rgb(17 24 39);
    }

    .dark .paramgold-activity-item__title {
        color: rgb(255 255 255);
    }

    .paramgold-activity-item__meta {
        margin: 0.125rem 0 0;
        font-size: 0.75rem;
        color: rgb(107 114 128);
    }

    .paramgold-status-pill {
        display: inline-flex;
        align-items: center;
        border-radius: 9999px;
        padding: 0.2rem 0.55rem;
        font-size: 0.6875rem;
        font-weight: 700;
        text-transform: uppercase;
    }

    .paramgold-status-pill--warning { background: rgb(255 237 213); color: rgb(194 65 12); }
    .paramgold-status-pill--success { background: rgb(220 252 231); color: rgb(21 128 61); }
    .paramgold-status-pill--info { background: rgb(219 234 254); color: rgb(29 78 216); }
    .paramgold-status-pill--danger { background: rgb(254 226 226); color: rgb(185 28 28); }
    .paramgold-status-pill--gray { background: rgb(243 244 246); color: rgb(75 85 99); }

    .paramgold-view-link {
        font-size: 0.75rem;
        font-weight: 600;
        color: rgb(180 83 9);
        text-decoration: none;
        white-space: nowrap;
    }
</style>
