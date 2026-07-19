<style>
    .fi-manager-order-stats-widget .manager-dashboard-section,
    .fi-manager-team-performance-widget .manager-dashboard-section,
    .fi-manager-employee-performance-widget .manager-dashboard-section {
        margin-bottom: 0;
    }

    .fi-manager-order-stats-widget .fi-section,
    .fi-manager-team-performance-widget .fi-section,
    .fi-manager-employee-performance-widget .fi-section {
        padding-bottom: 0;
    }

    .manager-team-stats-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr);
        gap: 1rem;
        padding-top: 0.25rem;
    }

    @media (min-width: 640px) {
        .manager-team-stats-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (min-width: 1024px) {
        .manager-team-stats-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }
    }

    .manager-team-stat-card {
        display: flex;
        min-height: 6.75rem;
        flex-direction: column;
        justify-content: space-between;
        border: 1px solid rgb(229 231 235);
        border-radius: 0.75rem;
        background: rgb(255 255 255);
        padding: 1rem 1.125rem;
    }

    .dark .manager-team-stat-card {
        border-color: rgb(55 65 81);
        background: rgb(17 24 39);
    }

    .manager-team-stat-card__label {
        margin: 0;
        font-size: 0.8125rem;
        font-weight: 600;
        line-height: 1.35;
        color: rgb(55 65 81);
        word-break: break-word;
    }

    .dark .manager-team-stat-card__label {
        color: rgb(209 213 219);
    }

    .manager-team-stat-card__value {
        margin: 0.75rem 0 0;
        font-size: 1.5rem;
        font-weight: 700;
        line-height: 1.2;
        color: rgb(17 24 39);
        word-break: break-word;
    }

    .dark .manager-team-stat-card__value {
        color: rgb(255 255 255);
    }

    .manager-team-stat-card--sales-target,
    .manager-team-stat-card--sales-achievement {
        border-top: 3px solid rgb(34 197 94);
    }

    .manager-team-stat-card--collection-target,
    .manager-team-stat-card--collection-achievement {
        border-top: 3px solid rgb(59 130 246);
    }

    .manager-team-progress-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr);
        gap: 1rem;
        margin-top: 1rem;
    }

    @media (min-width: 768px) {
        .manager-team-progress-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    .manager-team-progress-card {
        border: 1px solid rgb(229 231 235);
        border-radius: 0.75rem;
        background: rgb(249 250 251);
        padding: 0.875rem 1rem;
    }

    .dark .manager-team-progress-card {
        border-color: rgb(55 65 81);
        background: rgb(31 41 55);
    }

    .manager-order-stats-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr);
        gap: 1rem;
        padding-top: 0.25rem;
    }

    @media (min-width: 768px) {
        .manager-order-stats-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    .manager-order-stat-card {
        display: flex;
        min-height: 8.5rem;
        flex-direction: column;
        justify-content: space-between;
        border: 1px solid rgb(229 231 235);
        border-radius: 0.75rem;
        background: rgb(255 255 255);
        padding: 1rem 1.125rem;
        text-decoration: none;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }

    .dark .manager-order-stat-card {
        border-color: rgb(55 65 81);
        background: rgb(17 24 39);
    }

    .manager-order-stat-card:hover {
        border-color: rgb(245 158 11);
        box-shadow: 0 4px 14px rgba(15, 23, 42, 0.08);
    }

    .manager-order-stat-card__label {
        margin: 0;
        font-size: 0.875rem;
        font-weight: 600;
        line-height: 1.35;
        color: rgb(55 65 81);
        word-break: break-word;
    }

    .dark .manager-order-stat-card__label {
        color: rgb(209 213 219);
    }

    .manager-order-stat-card__value {
        margin: 0.75rem 0 0.375rem;
        font-size: 2rem;
        font-weight: 700;
        line-height: 1.1;
        color: rgb(17 24 39);
    }

    .dark .manager-order-stat-card__value {
        color: rgb(255 255 255);
    }

    .manager-order-stat-card__description {
        margin: 0;
        font-size: 0.75rem;
        line-height: 1.4;
        color: rgb(107 114 128);
    }

    .dark .manager-order-stat-card__description {
        color: rgb(156 163 175);
    }

    .manager-order-stat-card--warning {
        border-top: 3px solid rgb(245 158 11);
    }

    .manager-order-stat-card--success {
        border-top: 3px solid rgb(34 197 94);
    }

    .manager-order-stat-card--info {
        border-top: 3px solid rgb(59 130 246);
    }

    .manager-employee-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr);
        gap: 1rem;
    }

    @media (min-width: 1024px) {
        .manager-employee-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    .manager-employee-card {
        display: flex;
        height: 100%;
        min-width: 0;
        flex-direction: column;
        border: 1px solid rgb(229 231 235);
        border-radius: 0.75rem;
        background: rgb(255 255 255);
        padding: 1rem 1.125rem;
    }

    .dark .manager-employee-card {
        border-color: rgb(55 65 81);
        background: rgb(17 24 39);
    }

    .manager-employee-card__header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.75rem;
        margin-bottom: 0.75rem;
    }

    .manager-employee-card__identity {
        min-width: 0;
        flex: 1;
    }

    .manager-employee-card__name {
        margin: 0;
        font-size: 1rem;
        font-weight: 700;
        line-height: 1.35;
        color: rgb(17 24 39);
        word-break: break-word;
    }

    .dark .manager-employee-card__name {
        color: rgb(255 255 255);
    }

    .manager-employee-card__code {
        margin: 0.25rem 0 0;
        font-size: 0.75rem;
        line-height: 1.4;
        color: rgb(107 114 128);
    }

    .dark .manager-employee-card__code {
        color: rgb(156 163 175);
    }

    .manager-employee-card__section {
        margin-top: 0.875rem;
        padding-top: 0.875rem;
        border-top: 1px solid rgb(243 244 246);
    }

    .dark .manager-employee-card__section {
        border-top-color: rgb(31 41 55);
    }

    .manager-employee-card__section-title {
        margin: 0 0 0.625rem;
        font-size: 0.75rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: rgb(107 114 128);
    }

    .dark .manager-employee-card__section-title {
        color: rgb(156 163 175);
    }

    .manager-metric-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 0.75rem;
        align-items: center;
        margin-bottom: 0.375rem;
        font-size: 0.8125rem;
        line-height: 1.4;
    }

    .manager-metric-row__label {
        color: rgb(75 85 99);
        word-break: break-word;
    }

    .dark .manager-metric-row__label {
        color: rgb(209 213 219);
    }

    .manager-metric-row__value {
        font-weight: 600;
        color: rgb(17 24 39);
        white-space: nowrap;
    }

    .dark .manager-metric-row__value {
        color: rgb(255 255 255);
    }

    .manager-progress-track {
        width: 100%;
        height: 0.5rem;
        margin-top: 0.375rem;
        overflow: hidden;
        border-radius: 9999px;
        background: rgb(229 231 235);
    }

    .dark .manager-progress-track {
        background: rgb(55 65 81);
    }

    .manager-progress-bar {
        height: 100%;
        border-radius: 9999px;
        transition: width 0.2s ease;
    }

    .manager-progress-bar--sales {
        background: rgb(34 197 94);
    }

    .manager-progress-bar--collection {
        background: rgb(59 130 246);
    }

    .manager-order-summary-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.5rem;
    }

    .manager-order-badge {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        min-width: 0;
        border-radius: 0.5rem;
        padding: 0.625rem 0.375rem;
        text-align: center;
    }

    .manager-order-badge__label {
        margin: 0 0 0.25rem;
        font-size: 0.6875rem;
        font-weight: 600;
        line-height: 1.2;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }

    .manager-order-badge__count {
        margin: 0;
        font-size: 1.125rem;
        font-weight: 700;
        line-height: 1;
    }

    .manager-order-badge--pending {
        background: rgb(255 247 237);
        color: rgb(194 65 12);
    }

    .dark .manager-order-badge--pending {
        background: rgba(245, 158, 11, 0.12);
        color: rgb(251 191 36);
    }

    .manager-order-badge--approved {
        background: rgb(240 253 244);
        color: rgb(21 128 61);
    }

    .dark .manager-order-badge--approved {
        background: rgba(34, 197, 94, 0.12);
        color: rgb(74 222 128);
    }

    .manager-order-badge--dispatched {
        background: rgb(239 246 255);
        color: rgb(29 78 216);
    }

    .dark .manager-order-badge--dispatched {
        background: rgba(59, 130, 246, 0.12);
        color: rgb(96 165 250);
    }

    .manager-view-performance-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        border: 1px solid rgb(245 158 11);
        border-radius: 0.5rem;
        background: rgb(255 251 235);
        padding: 0.375rem 0.75rem;
        font-size: 0.75rem;
        font-weight: 600;
        line-height: 1.2;
        color: rgb(180 83 9);
        text-decoration: none;
        white-space: nowrap;
        transition: background-color 0.15s ease, color 0.15s ease;
    }

    .dark .manager-view-performance-btn {
        border-color: rgb(217 119 6);
        background: rgba(245, 158, 11, 0.1);
        color: rgb(251 191 36);
    }

    .manager-view-performance-btn:hover {
        background: rgb(254 243 199);
        color: rgb(146 64 14);
    }

    .dark .manager-view-performance-btn:hover {
        background: rgba(245, 158, 11, 0.2);
        color: rgb(253 224 71);
    }

    .manager-section-subtitle {
        margin: -0.25rem 0 0.75rem;
        font-size: 0.75rem;
        color: rgb(107 114 128);
    }

    .dark .manager-section-subtitle {
        color: rgb(156 163 175);
    }

    .manager-employee-section-header {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.875rem;
        margin-bottom: 1rem;
    }

    .manager-employee-section-header__title-wrap {
        min-width: 0;
        flex: 1 1 12rem;
    }

    .manager-employee-section-header__title {
        margin: 0;
        font-size: 1rem;
        font-weight: 700;
        line-height: 1.35;
        color: rgb(17 24 39);
    }

    .dark .manager-employee-section-header__title {
        color: rgb(255 255 255);
    }

    .manager-employee-section-header__subtitle,
    .manager-empty-state {
        margin: 0.25rem 0 0;
        font-size: 0.75rem;
        color: rgb(107 114 128);
    }

    .dark .manager-employee-section-header__subtitle,
    .dark .manager-empty-state {
        color: rgb(156 163 175);
    }

    .manager-employee-filters {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: flex-end;
        gap: 0.625rem;
        min-width: 0;
        flex: 1 1 18rem;
    }

    .manager-period-filters {
        display: flex;
        flex-wrap: wrap;
        gap: 0.375rem;
    }

    .manager-period-btn {
        border: 1px solid rgb(229 231 235);
        border-radius: 0.5rem;
        background: rgb(255 255 255);
        padding: 0.4375rem 0.75rem;
        font-size: 0.75rem;
        font-weight: 600;
        line-height: 1.2;
        color: rgb(55 65 81);
        cursor: pointer;
        transition: background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease;
    }

    .dark .manager-period-btn {
        border-color: rgb(75 85 99);
        background: rgb(31 41 55);
        color: rgb(209 213 219);
    }

    .manager-period-btn:hover {
        border-color: rgb(245 158 11);
        color: rgb(180 83 9);
    }

    .manager-period-btn--active {
        border-color: rgb(245 158 11);
        background: rgb(255 251 235);
        color: rgb(180 83 9);
    }

    .dark .manager-period-btn--active {
        border-color: rgb(217 119 6);
        background: rgba(245, 158, 11, 0.12);
        color: rgb(251 191 36);
    }

    .manager-employee-filter-select {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        min-width: 0;
        width: min(100%, 18rem);
    }

    .manager-employee-search,
    .manager-employee-select,
    .manager-custom-period-input {
        width: 100%;
        min-width: 0;
        border: 1px solid rgb(209 213 219);
        border-radius: 0.5rem;
        background: rgb(255 255 255);
        padding: 0.5rem 0.625rem;
        font-size: 0.8125rem;
        line-height: 1.3;
        color: rgb(17 24 39);
    }

    .dark .manager-employee-search,
    .dark .manager-employee-select,
    .dark .manager-custom-period-input {
        border-color: rgb(75 85 99);
        background: rgb(31 41 55);
        color: rgb(255 255 255);
    }

    .manager-employee-select {
        cursor: pointer;
    }

    .manager-custom-period-row {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-end;
        gap: 0.75rem;
        margin-bottom: 1rem;
        padding: 0.875rem;
        border: 1px solid rgb(229 231 235);
        border-radius: 0.75rem;
        background: rgb(249 250 251);
    }

    .dark .manager-custom-period-row {
        border-color: rgb(55 65 81);
        background: rgb(31 41 55);
    }

    .manager-custom-period-field {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
        min-width: min(100%, 11rem);
        flex: 1 1 11rem;
    }

    .manager-custom-period-label {
        font-size: 0.75rem;
        font-weight: 600;
        color: rgb(75 85 99);
    }

    .dark .manager-custom-period-label {
        color: rgb(209 213 219);
    }

    .manager-custom-period-error {
        margin: 0;
        font-size: 0.6875rem;
        color: rgb(220 38 38);
    }

    .manager-custom-period-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .manager-period-action-btn {
        border-radius: 0.5rem;
        padding: 0.5rem 0.875rem;
        font-size: 0.8125rem;
        font-weight: 600;
        line-height: 1.2;
        cursor: pointer;
    }

    .manager-period-action-btn--apply {
        border: 1px solid rgb(245 158 11);
        background: rgb(245 158 11);
        color: rgb(255 255 255);
    }

    .manager-period-action-btn--clear {
        border: 1px solid rgb(209 213 219);
        background: rgb(255 255 255);
        color: rgb(55 65 81);
    }

    .dark .manager-period-action-btn--clear {
        border-color: rgb(75 85 99);
        background: rgb(17 24 39);
        color: rgb(209 213 219);
    }
</style>
