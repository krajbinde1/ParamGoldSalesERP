@include('filament.widgets.partials.manager-dashboard-styles')
@include('filament.widgets.partials.admin-dashboard-styles')

<style>
    /*
     * Shared Admin main content container — Dashboard + Orders + all modules.
     * Balanced enterprise width (~1550px), centered after sidebar.
     */
    .paramgold-admin-shell .fi-main.fi-width-full,
    .paramgold-admin-shell .fi-main.fi-width-7xl {
        width: 100%;
        max-width: none;
        margin-left: auto;
        margin-right: auto;
        padding-left: 1rem;
        padding-right: 1rem;
        box-sizing: border-box;
    }

    @media (min-width: 768px) {
        .paramgold-admin-shell .fi-main.fi-width-full,
        .paramgold-admin-shell .fi-main.fi-width-7xl {
            padding-left: 1.25rem;
            padding-right: 1.25rem;
        }
    }

    @media (min-width: 1024px) {
        .paramgold-admin-shell .fi-main.fi-width-full,
        .paramgold-admin-shell .fi-main.fi-width-7xl {
            width: calc(100% - 40px);
            max-width: 1550px;
            padding-left: 1.25rem;
            padding-right: 1.25rem;
        }
    }

    @media (min-width: 1280px) {
        .paramgold-admin-shell .fi-main.fi-width-full,
        .paramgold-admin-shell .fi-main.fi-width-7xl {
            width: calc(100% - 48px);
            max-width: 1550px;
            padding-left: 1.5rem;
            padding-right: 1.5rem;
        }
    }

    @media (min-width: 1536px) {
        .paramgold-admin-shell .fi-main.fi-width-full,
        .paramgold-admin-shell .fi-main.fi-width-7xl {
            width: calc(100% - 48px);
            max-width: 1550px;
            padding-left: 1.5rem;
            padding-right: 1.5rem;
        }
    }

    .paramgold-admin-shell .fi-main {
        background: rgb(248 250 252);
    }

    .dark .paramgold-admin-shell .fi-main {
        background: rgb(15 23 42);
    }

    .paramgold-admin-shell .fi-section,
    .paramgold-admin-shell .fi-wi-widget .fi-section {
        border-radius: 0.75rem;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    }

    .paramgold-admin-shell .fi-page-main {
        gap: 0.875rem;
    }

    /*
     * Admin list / table standard - Dealer List is the reference.
     * Scoped to Filament tables (.fi-ta) so custom report/review tables are unchanged.
     */
    .paramgold-admin-shell .fi-header:not(.pg-order-view-header):not(.pg-bom-view-header) {
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 0.125rem;
    }

    .paramgold-admin-shell .fi-header:not(.pg-order-view-header):not(.pg-bom-view-header) .fi-header-heading {
        font-size: 1.375rem;
        font-weight: 700;
        line-height: 1.3;
        letter-spacing: -0.02em;
    }

    .paramgold-admin-shell .fi-header-actions-ctn {
        gap: 0.5rem;
    }

    .paramgold-admin-shell .fi-ta-ctn {
        overflow-x: auto;
        border-radius: 0.75rem;
    }

    .paramgold-admin-shell .fi-ta .fi-ta-ctn {
        overflow: hidden;
        border-radius: 0.75rem;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    }

    .paramgold-admin-shell .fi-ta .fi-ta-header-toolbar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.5rem 0.75rem;
        padding: 0.625rem 0.85rem;
    }

    .paramgold-admin-shell .fi-ta .fi-ta-header-toolbar > div:last-child {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: flex-end;
        gap: 0.5rem;
        margin-left: auto;
    }

    .paramgold-admin-shell .fi-ta .fi-ta-search-field {
        width: 15.5rem;
        max-width: 100%;
        flex: 0 0 auto;
    }

    .paramgold-admin-shell .fi-ta .fi-ta-search-field .fi-input-wrp,
    .paramgold-admin-shell .fi-ta .fi-ta-search-field .fi-input-wrapper {
        min-height: 2.25rem;
    }

    .paramgold-admin-shell .fi-ta .fi-ta-filters-dropdown,
    .paramgold-admin-shell .fi-ta .fi-ta-col-manager-dropdown {
        flex: 0 0 auto;
    }

    .paramgold-admin-shell .fi-ta .fi-ta-content {
        overflow-x: auto;
        overflow-y: visible;
    }

    .paramgold-admin-shell .fi-ta .fi-ta-table:not(.erp-mat-table):not(.erp-cost-table) {
        width: max-content;
        min-width: 100%;
        table-layout: auto;
    }

    .paramgold-admin-shell .fi-ta .fi-ta-header-cell {
        font-size: 0.75rem;
        font-weight: 600;
        line-height: 1.25;
        letter-spacing: 0.01em;
        height: 2.5rem;
        padding: 0.5rem 0.75rem;
        white-space: nowrap;
        vertical-align: middle;
    }

    .paramgold-admin-shell .fi-ta .fi-ta-cell {
        font-size: 0.8125rem;
        line-height: 1.3;
        min-height: 2.75rem;
        padding: 0.45rem 0.75rem;
        white-space: nowrap;
        vertical-align: middle;
    }

    .paramgold-admin-shell .fi-ta .fi-ta-header-cell:not(.fi-growable),
    .paramgold-admin-shell .fi-ta .fi-ta-cell:not(.fi-growable) {
        width: 1%;
    }

    .paramgold-admin-shell .fi-ta .fi-ta-header-cell.fi-growable,
    .paramgold-admin-shell .fi-ta .fi-ta-cell.fi-growable {
        width: auto;
        min-width: 8rem;
    }

    .paramgold-admin-shell .fi-ta .fi-ta-table:not(.fi-ta-table-stacked-on-mobile) > thead > tr > .fi-ta-header-cell:nth-last-child(2):not(.fi-ta-actions-header-cell),
    .paramgold-admin-shell .fi-ta .fi-ta-table:not(.fi-ta-table-stacked-on-mobile) > tbody > tr.fi-ta-row > .fi-ta-cell:nth-last-child(2):not(:has(.fi-ta-actions)) {
        width: auto;
        min-width: 6rem;
    }

    .paramgold-admin-shell .fi-ta .fi-ta-actions-header-cell,
    .paramgold-admin-shell .fi-ta .fi-ta-cell:has(.fi-ta-actions) {
        width: 1%;
        white-space: nowrap;
        padding-left: 0.5rem;
        padding-right: 0.75rem;
        text-align: end;
    }

    @media (min-width: 768px) {
        .paramgold-admin-shell .fi-ta .fi-ta-table:not(.fi-ta-table-stacked-on-mobile) .fi-ta-actions-header-cell,
        .paramgold-admin-shell .fi-ta .fi-ta-table:not(.fi-ta-table-stacked-on-mobile) .fi-ta-cell:has(.fi-ta-actions) {
            position: sticky;
            right: 0;
            z-index: 2;
            background-color: rgb(255 255 255);
            box-shadow: -6px 0 8px -8px rgba(15, 23, 42, 0.18);
        }

        .dark .paramgold-admin-shell .fi-ta .fi-ta-table:not(.fi-ta-table-stacked-on-mobile) .fi-ta-actions-header-cell,
        .dark .paramgold-admin-shell .fi-ta .fi-ta-table:not(.fi-ta-table-stacked-on-mobile) .fi-ta-cell:has(.fi-ta-actions) {
            background-color: rgb(15 23 42);
        }

        .paramgold-admin-shell .fi-ta .fi-ta-row:hover > .fi-ta-cell:has(.fi-ta-actions) {
            background-color: rgb(248 250 252);
        }

        .dark .paramgold-admin-shell .fi-ta .fi-ta-row:hover > .fi-ta-cell:has(.fi-ta-actions) {
            background-color: rgb(30 41 59);
        }
    }

    .paramgold-admin-shell .fi-ta .fi-ta-cell .fi-ta-actions {
        display: inline-flex;
        flex-wrap: nowrap;
        align-items: center;
        justify-content: flex-end;
        gap: 0.25rem;
        width: max-content;
        max-width: none;
    }

    .paramgold-admin-shell .fi-ta .fi-ta-cell .fi-ta-actions .fi-ac-btn,
    .paramgold-admin-shell .fi-ta .fi-ta-cell .fi-ta-actions .fi-link,
    .paramgold-admin-shell .fi-ta .fi-ta-cell .fi-ta-actions .fi-btn,
    .paramgold-admin-shell .fi-ta .fi-ta-cell .fi-ta-actions a,
    .paramgold-admin-shell .fi-ta .fi-ta-cell .fi-ta-actions button {
        white-space: nowrap;
        flex: 0 0 auto;
    }

    .paramgold-admin-shell .fi-ta .fi-pagination {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem 0.75rem;
        padding: 0.5rem 0.85rem;
        border-top: 1px solid rgb(226 232 240);
    }

    .dark .paramgold-admin-shell .fi-ta .fi-pagination {
        border-top-color: rgb(51 65 85);
    }

    .paramgold-admin-shell .fi-ta .fi-pagination-overview {
        font-size: 0.75rem;
        color: rgb(100 116 139);
    }

    .paramgold-admin-shell .fi-ta .fi-ta-table-stacked-on-mobile .fi-ta-header-cell,
    .paramgold-admin-shell .fi-ta .fi-ta-table-stacked-on-mobile .fi-ta-cell {
        width: auto;
        height: auto;
        max-height: none;
        white-space: normal;
    }

    .paramgold-admin-shell .fi-ta .fi-ta-cell-vendor-name,
    .paramgold-admin-shell .fi-ta .fi-ta-cell-remark,
    .paramgold-admin-shell .fi-ta .fi-ta-header-cell-vendor-name,
    .paramgold-admin-shell .fi-ta .fi-ta-header-cell-remark {
        white-space: normal;
        max-width: 12.5rem;
        height: auto;
    }

    .paramgold-welcome-card {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        border: 1px solid rgb(226 232 240);
        border-radius: 0.75rem;
        background: #fff;
        padding: 1rem 1.25rem;
    }

    .paramgold-welcome-card__title {
        margin: 0;
        font-size: 1.25rem;
        font-weight: 800;
        color: rgb(15 23 42);
    }

    .paramgold-welcome-card__meta,
    .paramgold-welcome-card__subtitle {
        margin: 0.2rem 0 0;
        font-size: 0.8125rem;
        color: rgb(100 116 139);
    }

    .paramgold-welcome-card__avatar {
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
        border: 1px solid rgb(226 232 240);
        border-radius: 0.75rem;
        background: rgb(255 255 255);
        padding: 0.875rem 1rem;
        text-decoration: none;
    }

    .paramgold-summary-card--warning { border-left: 3px solid rgb(245 158 11); }
    .paramgold-summary-card--success { border-left: 3px solid rgb(34 197 94); }
    .paramgold-summary-card--info { border-left: 3px solid rgb(59 130 246); }
    .paramgold-summary-card--danger { border-left: 3px solid rgb(239 68 68); }
    .paramgold-summary-card--primary { border-left: 3px solid rgb(15 118 110); }

    .paramgold-summary-card__label {
        margin: 0;
        font-size: 0.75rem;
        font-weight: 600;
        color: rgb(100 116 139);
    }

    .paramgold-summary-card__value {
        margin: 0.5rem 0 0;
        font-size: 1.35rem;
        font-weight: 800;
        color: rgb(15 23 42);
    }

    .paramgold-quick-actions-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.65rem;
    }

    @media (min-width: 768px) {
        .paramgold-quick-actions-grid {
            grid-template-columns: repeat(5, minmax(0, 1fr));
        }
    }

    .paramgold-quick-action {
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 2.75rem;
        border-radius: 0.65rem;
        border: 1px solid rgb(226 232 240);
        background: #fff;
        padding: 0.625rem 0.875rem;
        font-size: 0.8125rem;
        font-weight: 650;
        text-decoration: none;
        text-align: center;
        color: rgb(30 41 59);
    }

    .paramgold-activity-tabs {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-bottom: 1rem;
    }

    .paramgold-activity-list {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .paramgold-activity-item {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 0.75rem;
        align-items: center;
        border: 1px solid rgb(226 232 240);
        border-radius: 0.65rem;
        padding: 0.75rem 0.875rem;
        background: rgb(255 255 255);
    }

    .paramgold-activity-item__title {
        margin: 0;
        font-size: 0.875rem;
        font-weight: 650;
        color: rgb(15 23 42);
    }

    .paramgold-activity-item__meta {
        margin: 0.125rem 0 0;
        font-size: 0.75rem;
        color: rgb(100 116 139);
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
        font-weight: 700;
        color: rgb(15 118 110);
        text-decoration: none;
        white-space: nowrap;
    }

    .total-outstanding-page .paramgold-summary-grid {
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    }

    @media (min-width: 1024px) {
        .total-outstanding-page .paramgold-summary-grid {
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        }
    }

</style>
