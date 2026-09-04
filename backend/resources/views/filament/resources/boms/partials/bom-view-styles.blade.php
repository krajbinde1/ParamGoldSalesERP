<style>
    .pg-bom-view-page .fi-page-main {
        gap: 0.75rem;
    }

    .pg-bom-view-page .fi-sc-section,
    .pg-bom-view-page .fi-section {
        background: transparent;
        border: 0;
        box-shadow: none;
        border-radius: 0;
    }

    .pg-bom-view-page .fi-sc-section-content,
    .pg-bom-view-page .fi-section-content,
    .pg-bom-view-page .fi-section-content-ctn {
        padding: 0 !important;
    }

    .pg-bom-view-page .fi-sc-section-header,
    .pg-bom-view-page .fi-section-header {
        display: none;
    }

    .pg-bom-view-page .fi-in-entry,
    .pg-bom-view-page .fi-in-text-entry {
        gap: 0;
    }

    .pg-bom-view-header.fi-header {
        align-items: flex-start;
        gap: 0.75rem 1rem;
        margin-bottom: 0.25rem;
    }

    .pg-bom-view-header__main {
        min-width: 0;
        flex: 1 1 auto;
    }

    .pg-bom-view-header__title {
        margin: 0;
        font-size: 1.375rem;
        font-weight: 700;
        line-height: 1.25;
        color: #0F172A;
        letter-spacing: -0.02em;
    }

    .pg-bom-view-header__product {
        margin: 0.2rem 0 0;
        font-size: 0.9375rem;
        font-weight: 600;
        line-height: 1.35;
        color: #334155;
    }

    .pg-bom-view-header__meta {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.4rem 0.65rem;
        margin-top: 0.55rem;
    }

    .pg-bom-view-header__badge {
        display: inline-flex;
        align-items: center;
        padding: 0.15rem 0.55rem;
        border-radius: 9999px;
        font-size: 0.6875rem;
        font-weight: 700;
        line-height: 1.35;
        letter-spacing: 0.01em;
        white-space: nowrap;
    }

    .pg-bom-view-header__date {
        margin: 0;
        font-size: 0.8125rem;
        line-height: 1.35;
        color: #64748B;
    }

    .pg-bom-view-header__date span {
        color: #94A3B8;
    }

    .pg-bom-view-header .fi-header-actions-ctn {
        flex-shrink: 0;
        margin-top: 0.15rem;
    }

    .pg-bom-notes {
        margin: 0;
        padding: 0.65rem 0.85rem;
        border: 1px solid #E2E8F0;
        border-radius: 0.75rem;
        background: #fff;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        font-size: 0.8125rem;
        line-height: 1.45;
        color: #334155;
    }

    .pg-bom-notes strong {
        color: #64748B;
        font-weight: 600;
        margin-right: 0.35rem;
    }

    .pg-bom-card {
        width: 100%;
        border: 1px solid #E2E8F0;
        border-radius: 0.75rem;
        background: #fff;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        overflow: hidden;
    }

    .pg-bom-card__head {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 0.75rem;
        padding: 0.7rem 0.9rem 0.6rem;
        border-bottom: 1px solid #F1F5F9;
    }

    .pg-bom-card__title {
        margin: 0;
        font-size: 0.75rem;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: #64748B;
    }

    .pg-bom-card__count {
        margin: 0;
        font-size: 0.75rem;
        font-weight: 600;
        color: #94A3B8;
    }

    .pg-bom-items__scroll {
        width: 100%;
        overflow-x: auto;
    }

    .pg-bom-items__table {
        width: 100%;
        min-width: 720px;
        border-collapse: separate;
        border-spacing: 0;
        font-size: 0.8125rem;
    }

    .pg-bom-items__table th {
        padding: 0.5rem 0.85rem;
        text-align: left;
        font-size: 0.6875rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: #64748B;
        background: #F8FAFC;
        border-bottom: 1px solid #E2E8F0;
        white-space: nowrap;
    }

    .pg-bom-items__table th.num,
    .pg-bom-items__table td.num {
        text-align: right;
        font-variant-numeric: tabular-nums;
        white-space: nowrap;
    }

    .pg-bom-items__table td {
        padding: 0.55rem 0.85rem;
        vertical-align: middle;
        border-bottom: 1px solid #F1F5F9;
        color: #0F172A;
        line-height: 1.35;
    }

    .pg-bom-items__table tbody tr:last-child td {
        border-bottom: 0;
    }

    .pg-bom-items__table tbody tr:hover td {
        background: #F8FAFC;
    }

    .pg-bom-items__name {
        margin: 0;
        font-weight: 600;
        color: #0F172A;
        max-width: 28rem;
        word-break: break-word;
    }

    .pg-bom-items__code {
        margin: 0.1rem 0 0;
        font-size: 0.75rem;
        color: #94A3B8;
        line-height: 1.3;
    }

    .pg-bom-items__remarks {
        color: #64748B;
        max-width: 16rem;
        word-break: break-word;
    }

    .pg-bom-items__empty {
        padding: 1.25rem 0.85rem;
        text-align: center;
        color: #94A3B8;
    }

    .pg-bom-type {
        display: inline-flex;
        align-items: center;
        padding: 0.12rem 0.5rem;
        border-radius: 9999px;
        font-size: 0.6875rem;
        font-weight: 700;
        line-height: 1.35;
        white-space: nowrap;
    }

    .pg-bom-type--raw {
        background: #EFF6FF;
        color: #1D4ED8;
        border: 1px solid #BFDBFE;
    }

    .pg-bom-type--bulk {
        background: #F0FDFA;
        color: #0F766E;
        border: 1px solid #99F6E4;
    }

    .pg-bom-type--pack {
        background: #FFFBEB;
        color: #B45309;
        border: 1px solid #FDE68A;
    }

    .pg-bom-summary {
        padding: 0.75rem 0.9rem 0.9rem;
    }

    .pg-bom-summary__grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.55rem;
    }

    .pg-bom-kpi {
        min-width: 0;
        padding: 0.55rem 0.7rem;
        border: 1px solid #F1F5F9;
        border-radius: 0.6rem;
        background: #F8FAFC;
    }

    .pg-bom-kpi__label {
        margin: 0 0 0.2rem;
        font-size: 0.6875rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: #94A3B8;
    }

    .pg-bom-kpi__value {
        margin: 0;
        font-size: 0.9375rem;
        font-weight: 700;
        color: #0F172A;
        font-variant-numeric: tabular-nums;
        letter-spacing: -0.01em;
        line-height: 1.3;
        word-break: break-word;
    }

    .pg-bom-kpi__hint {
        margin: 0.15rem 0 0;
        font-size: 0.6875rem;
        font-weight: 500;
        color: #94A3B8;
        line-height: 1.3;
    }

    .pg-bom-summary__emphasis {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.55rem;
        margin-top: 0.65rem;
    }

    .pg-bom-kpi--emphasis {
        background: #F0FDFA;
        border-color: #99F6E4;
        padding: 0.7rem 0.85rem;
    }

    .pg-bom-kpi--emphasis .pg-bom-kpi__label {
        color: #0F766E;
    }

    .pg-bom-kpi--emphasis .pg-bom-kpi__value {
        font-size: 1.25rem;
        font-weight: 800;
        color: #0F766E;
        letter-spacing: -0.03em;
    }

    @media (min-width: 768px) {
        .pg-bom-view-header.fi-header {
            align-items: center;
        }

        .pg-bom-summary__grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .pg-bom-summary__emphasis {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (min-width: 1280px) {
        .pg-bom-summary__grid {
            grid-template-columns: repeat(6, minmax(0, 1fr));
        }

        .pg-bom-items__table {
            min-width: 0;
        }
    }

    .dark .pg-bom-view-header__title,
    .dark .pg-bom-items__name,
    .dark .pg-bom-kpi__value {
        color: #F8FAFC;
    }

    .dark .pg-bom-card,
    .dark .pg-bom-notes,
    .dark .pg-bom-kpi {
        background: rgb(30 41 59);
        border-color: rgb(51 65 85);
    }

    .dark .pg-bom-items__table th,
    .dark .pg-bom-items__table tbody tr:hover td {
        background: rgb(15 23 42);
    }

    .dark .pg-bom-kpi--emphasis {
        background: rgba(15, 118, 110, 0.16);
        border-color: rgba(153, 246, 228, 0.35);
    }
</style>
