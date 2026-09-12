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

    .paramgold-admin-shell .fi-main-ctn,
    .paramgold-admin-shell .fi-page,
    .paramgold-admin-shell .fi-page-content,
    .paramgold-admin-shell .fi-header-widgets {
        width: 100%;
        max-width: 100%;
        min-width: 0;
    }

    /*
     * Admin list / table standard - Dealer List is the reference.
     * Scoped to Filament tables (.fi-ta) so custom report/review tables are unchanged.
     */
    .paramgold-admin-shell .fi-header:not(.pg-order-view-header):not(.pg-bom-view-header) {
        display: flex;
        flex-wrap: wrap;
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

    .paramgold-admin-shell .fi-header-actions-ctn,
    .paramgold-admin-shell .fi-ac-header-actions,
    .paramgold-admin-shell .fi-header-actions {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: flex-end;
        gap: 0.5rem;
        max-width: 100%;
    }

    .paramgold-admin-shell .fi-ta-ctn {
        overflow-x: auto;
        border-radius: 0.75rem;
    }

    .paramgold-admin-shell .fi-ta .fi-ta-ctn {
        width: 100%;
        max-width: 100%;
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
        width: 100%;
        max-width: 100%;
        overflow-x: auto;
        overflow-y: visible;
    }

    .paramgold-admin-shell .fi-ta .fi-ta-table:not(.erp-mat-table):not(.erp-cost-table) {
        width: 100%;
        min-width: 100%;
        table-layout: auto;
    }

    .paramgold-admin-shell .erp-production-review .erp-mat-table {
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
        overflow: hidden;
        text-overflow: ellipsis;
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
        max-width: 28rem;
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
        grid-template-columns: repeat(auto-fit, minmax(min(100%, 13.75rem), 1fr));
        gap: 0.875rem;
        width: 100%;
        align-items: stretch;
    }

    .paramgold-summary-card {
        display: flex;
        min-height: 6.5rem;
        height: 100%;
        width: 100%;
        min-width: 0;
        overflow: hidden;
        flex-direction: column;
        justify-content: space-between;
        border: 1px solid rgb(226 232 240);
        border-radius: 0.75rem;
        background: rgb(255 255 255);
        padding: 0.875rem 1rem;
        text-decoration: none;
        box-sizing: border-box;
        color: inherit;
    }

    .paramgold-summary-card--warning { border-left: 3px solid rgb(245 158 11); }
    .paramgold-summary-card--success { border-left: 3px solid rgb(34 197 94); }
    .paramgold-summary-card--info { border-left: 3px solid rgb(59 130 246); }
    .paramgold-summary-card--danger { border-left: 3px solid rgb(239 68 68); }
    .paramgold-summary-card--primary { border-left: 3px solid rgb(15 118 110); }

    .paramgold-summary-card--clickable {
        cursor: pointer;
        text-align: start;
        transition: box-shadow 0.15s ease, border-color 0.15s ease, background-color 0.15s ease;
    }

    .paramgold-summary-card--clickable:hover,
    .paramgold-summary-card--clickable:focus-visible {
        box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
        outline: none;
    }

    .paramgold-summary-card--active {
        box-shadow: 0 0 0 2px rgb(15 118 110);
        background: rgb(240 253 250);
    }

    .paramgold-summary-card__label {
        margin: 0;
        font-size: 0.75rem;
        font-weight: 600;
        color: rgb(100 116 139);
        line-height: 1.35;
        overflow-wrap: break-word;
        word-break: break-word;
        white-space: normal;
    }

    .paramgold-summary-card__value {
        margin: 0.5rem 0 0;
        font-size: 1.35rem;
        font-weight: 800;
        color: rgb(15 23 42);
        line-height: 1.2;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        word-break: normal;
        overflow-wrap: normal;
    }

    .paramgold-summary-card__value--wrap {
        white-space: normal;
        overflow: visible;
        text-overflow: unset;
        font-size: 1.05rem;
        line-height: 1.35;
    }

    .paramgold-summary-card__meta {
        margin: 0.4rem 0 0;
        font-size: 0.75rem;
        line-height: 1.35;
        color: rgb(100 116 139);
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

    /*
     * Filament StatsOverviewWidget — same card size as paramgold-summary-card.
     */
    .paramgold-admin-shell .fi-wi-stats-overview {
        width: 100%;
    }

    .paramgold-admin-shell .fi-wi-stats-overview .fi-grid,
    .paramgold-admin-shell .fi-wi-stats-overview .fi-sc,
    .paramgold-admin-shell .fi-wi-paramgold-summary {
        width: 100%;
        max-width: 100%;
        gap: 0.875rem;
        align-items: stretch;
    }

    .paramgold-admin-shell .fi-wi-stats-overview .fi-grid {
        grid-template-columns: repeat(auto-fit, minmax(min(100%, 13.75rem), 1fr));
    }

    .paramgold-admin-shell .fi-wi-paramgold-summary .fi-section,
    .paramgold-admin-shell .fi-wi-paramgold-summary .fi-wi-widget {
        background: transparent;
        border: 0;
        box-shadow: none;
        padding: 0;
    }

    .paramgold-admin-shell .fi-wi-stats-overview-stat {
        display: flex;
        min-height: 6.5rem;
        height: 100%;
        min-width: 0;
        overflow: hidden;
        flex-direction: column;
        justify-content: space-between;
        border: 1px solid rgb(226 232 240);
        border-radius: 0.75rem;
        background: rgb(255 255 255);
        padding: 0.875rem 1rem;
        box-sizing: border-box;
        text-decoration: none;
    }

    .paramgold-admin-shell a.fi-wi-stats-overview-stat {
        cursor: pointer;
    }

    .paramgold-admin-shell .fi-wi-stats-overview-stat-label {
        font-size: 0.75rem;
        font-weight: 600;
        color: rgb(100 116 139);
        line-height: 1.35;
        overflow-wrap: break-word;
        word-break: break-word;
    }

    .paramgold-admin-shell .fi-wi-stats-overview-stat-value {
        margin-top: 0.5rem;
        font-size: 1.35rem;
        font-weight: 800;
        line-height: 1.2;
        color: rgb(15 23 42);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        word-break: normal;
        overflow-wrap: normal;
    }

    .paramgold-admin-shell .fi-wi-stats-overview-stat-description {
        margin-top: 0.4rem;
        font-size: 0.75rem;
        color: rgb(100 116 139);
    }

    /*
     * Filter dropdown / drawer stays inside the viewport.
     */
    .paramgold-admin-shell .fi-ta-filters {
        max-height: min(70vh, 36rem);
        overflow-x: hidden;
        overflow-y: auto;
        padding-right: 0.15rem;
    }

    .paramgold-admin-shell .fi-ta-filters-dropdown .fi-dropdown-panel,
    .paramgold-admin-shell .fi-dropdown-panel:has(.fi-ta-filters),
    .paramgold-admin-shell .fi-modal-window:has(.fi-ta-filters) {
        max-width: min(22rem, calc(100vw - 1.5rem));
        width: min(22rem, calc(100vw - 1.5rem));
        max-height: min(80vh, 40rem);
        overflow-x: hidden;
        overflow-y: auto;
    }

    .paramgold-admin-shell .fi-ta-filters .fi-fo-field,
    .paramgold-admin-shell .fi-ta-filters .fi-input-wrp {
        min-height: 2.5rem;
        width: 100%;
    }

    .paramgold-admin-shell .inventory-reports-filters {
        width: 100%;
        max-width: 100%;
        overflow: hidden;
    }

    .paramgold-admin-shell .inventory-reports-table-wrap {
        width: 100%;
        max-width: 100%;
        overflow-x: auto;
    }

    @media (max-width: 767px) {
        .paramgold-admin-shell .fi-header:not(.pg-order-view-header):not(.pg-bom-view-header) {
            align-items: stretch;
        }

        .paramgold-admin-shell .fi-header-actions-ctn {
            justify-content: flex-start;
            width: 100%;
        }

        .paramgold-admin-shell .fi-ta .fi-ta-search-field {
            width: 100%;
            flex: 1 1 100%;
        }

        .paramgold-admin-shell .fi-ta .fi-ta-header-toolbar > div:last-child {
            width: 100%;
            margin-left: 0;
            justify-content: flex-start;
        }

        .paramgold-admin-shell .fi-dropdown-panel:has(.fi-ta-filters),
        .paramgold-admin-shell .fi-modal-window:has(.fi-ta-filters) {
            max-width: calc(100vw - 1rem);
            width: calc(100vw - 1rem);
        }
    }

    .dark .paramgold-summary-card,
    .dark .paramgold-admin-shell .fi-wi-stats-overview-stat {
        background: rgb(15 23 42);
        border-color: rgb(51 65 85);
    }

    .dark .paramgold-summary-card__label,
    .dark .paramgold-summary-card__meta,
    .dark .paramgold-admin-shell .fi-wi-stats-overview-stat-label,
    .dark .paramgold-admin-shell .fi-wi-stats-overview-stat-description {
        color: rgb(148 163 184);
    }

    .dark .paramgold-summary-card__value,
    .dark .paramgold-admin-shell .fi-wi-stats-overview-stat-value {
        color: rgb(248 250 252);
    }

    .dark .paramgold-summary-card--active {
        background: rgb(19 78 74);
        box-shadow: 0 0 0 2px rgb(45 212 191);
    }

    .total-outstanding-page .paramgold-summary-grid {
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    }

    @media (min-width: 1024px) {
        .total-outstanding-page .paramgold-summary-grid {
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        }
    }

    /*
     * Purchase Entry form — consistent field sizing, no squeezed dropdowns,
     * and a professional responsive Purchase Items layout.
     */
    .paramgold-purchase-form .fi-fo-field {
        min-width: 0;
        width: 100%;
    }

    .paramgold-purchase-form .fi-fo-field-label-col {
        min-height: 2.35rem;
        display: flex;
        align-items: flex-end;
        margin-bottom: 0.3rem;
    }

    .paramgold-purchase-form .fi-fo-field-label,
    .paramgold-purchase-form .fi-fo-field-label-content {
        display: block;
        width: 100%;
        min-width: 0;
        line-height: 1.3;
        white-space: normal;
        overflow: visible;
        word-break: normal;
        overflow-wrap: break-word;
        hyphens: none;
    }

    .paramgold-purchase-form .fi-fo-file-upload .fi-input-wrp,
    .paramgold-purchase-form .fi-fo-file-upload {
        height: auto;
        min-height: 0;
        min-width: 0;
    }

    .paramgold-purchase-form .fi-input-wrp,
    .paramgold-purchase-form .fi-fo-select,
    .paramgold-purchase-form .fi-fo-date-picker,
    .paramgold-purchase-form .fi-fo-date-time-picker {
        min-height: 2.5rem;
        height: 2.5rem;
        min-width: 8.5rem;
        width: 100%;
        box-sizing: border-box;
    }

    .paramgold-purchase-form textarea,
    .paramgold-purchase-form .fi-textarea,
    .paramgold-purchase-form .fi-fo-textarea .fi-input-wrp {
        min-height: 4.25rem;
        height: auto;
        min-width: 8.5rem;
    }

    .paramgold-purchase-form .fi-input,
    .paramgold-purchase-form .fi-select-input,
    .paramgold-purchase-form .fi-select-input-btn,
    .paramgold-purchase-form input:not([type="file"]):not([type="checkbox"]):not([type="radio"]),
    .paramgold-purchase-form select {
        min-width: 0;
        width: 100%;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        word-break: normal;
        overflow-wrap: normal;
    }

    .paramgold-purchase-form .paramgold-purchase-dropdown,
    .paramgold-purchase-form .paramgold-purchase-material {
        min-width: 12rem;
    }

    .paramgold-purchase-form .paramgold-purchase-material {
        min-width: 16rem;
    }

    .paramgold-purchase-header-grid,
    .paramgold-purchase-item-row {
        width: 100%;
    }

    .paramgold-purchase-header-grid .fi-sc,
    .paramgold-purchase-item-row .fi-sc {
        align-items: start;
        column-gap: 0.875rem;
        row-gap: 0.875rem;
    }

    .paramgold-purchase-items-section .fi-section-content {
        overflow-x: visible;
    }

    .paramgold-purchase-items .fi-fo-repeater-item {
        padding: 0.85rem 0.9rem 0.65rem;
    }

    .paramgold-purchase-item-row--primary .paramgold-purchase-material {
        flex: 1 1 16rem;
    }

    .paramgold-purchase-summary .fi-fo-placeholder,
    .paramgold-purchase-summary .fi-fo-field {
        min-width: 10rem;
    }

    @media (max-width: 767px) {
        .paramgold-purchase-form .fi-input-wrp,
        .paramgold-purchase-form .fi-fo-select,
        .paramgold-purchase-form .fi-fo-date-picker,
        .paramgold-purchase-form .fi-fo-date-time-picker,
        .paramgold-purchase-form .paramgold-purchase-dropdown,
        .paramgold-purchase-form .paramgold-purchase-material {
            min-width: 0;
            width: 100%;
        }

        .paramgold-purchase-form .fi-fo-field-label-col {
            min-height: 0;
            align-items: flex-start;
        }

        .paramgold-purchase-items .fi-fo-repeater-item {
            padding: 0.75rem 0.65rem 0.5rem;
        }
    }

    @media (min-width: 768px) and (max-width: 1023px) {
        .paramgold-purchase-form .fi-input-wrp,
        .paramgold-purchase-form .fi-fo-select,
        .paramgold-purchase-form .fi-fo-date-picker,
        .paramgold-purchase-form .fi-fo-date-time-picker {
            min-width: 9.5rem;
        }

        .paramgold-purchase-form .paramgold-purchase-material {
            min-width: 14rem;
        }
    }

</style>
