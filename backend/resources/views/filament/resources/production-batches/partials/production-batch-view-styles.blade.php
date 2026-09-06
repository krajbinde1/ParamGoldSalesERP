<style>
    .pg-production-batch-view-page .fi-page-main {
        gap: 0.75rem;
    }

    .pg-production-batch-view-page .fi-sc-section,
    .pg-production-batch-view-page .fi-section {
        background: transparent;
        border: 0;
        box-shadow: none;
        border-radius: 0;
    }

    .pg-production-batch-view-page .fi-sc-section-content,
    .pg-production-batch-view-page .fi-section-content,
    .pg-production-batch-view-page .fi-section-content-ctn {
        padding: 0 !important;
    }

    .pg-production-batch-view-page .fi-sc-section-header,
    .pg-production-batch-view-page .fi-section-header {
        display: none;
    }

    .pg-production-batch-view-page .fi-in-entry,
    .pg-production-batch-view-page .fi-in-text-entry {
        gap: 0;
    }

    .pg-pb-view {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
    }

    .pg-pb-top {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.75rem;
        align-items: start;
    }

    .pg-pb-card {
        height: auto;
        align-self: start;
        border: 1px solid #E2E8F0;
        border-radius: 0.75rem;
        background: #fff;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        overflow: hidden;
    }

    .pg-pb-card__head {
        padding: 0.55rem 0.85rem 0.45rem;
        border-bottom: 1px solid #F1F5F9;
    }

    .pg-pb-card__title {
        margin: 0;
        font-size: 0.6875rem;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: #64748B;
    }

    .pg-pb-card__body {
        padding: 0.55rem 0.85rem 0.7rem;
    }

    .pg-pb-dl {
        display: grid;
        grid-template-columns: 8.75rem minmax(0, 1fr);
        column-gap: 0.65rem;
        row-gap: 0.4rem;
        align-items: baseline;
        margin: 0;
    }

    .pg-pb-dl dt {
        margin: 0;
        font-size: 0.75rem;
        font-weight: 500;
        color: #64748B;
        line-height: 1.35;
    }

    .pg-pb-dl dd {
        margin: 0;
        min-width: 0;
        font-size: 0.8125rem;
        font-weight: 600;
        color: #0F172A;
        line-height: 1.35;
        overflow-wrap: anywhere;
        font-variant-numeric: tabular-nums;
    }

    .pg-pb-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.1rem 0.45rem;
        border-radius: 9999px;
        font-size: 0.6875rem;
        font-weight: 700;
        line-height: 1.3;
    }

    .pg-pb-badge--success { background: #ECFDF5; color: #047857; border: 1px solid #A7F3D0; }
    .pg-pb-badge--danger { background: #FEF2F2; color: #B91C1C; border: 1px solid #FECACA; }
    .pg-pb-badge--info { background: #EFF6FF; color: #1D4ED8; border: 1px solid #BFDBFE; }
    .pg-pb-badge--warning { background: #FFFBEB; color: #B45309; border: 1px solid #FDE68A; }
    .pg-pb-badge--gray { background: #F8FAFC; color: #475569; border: 1px solid #E2E8F0; }

    .pg-pb-table-wrap {
        overflow-x: auto;
    }

    .pg-pb-table {
        width: 100%;
        min-width: 44rem;
        border-collapse: separate;
        border-spacing: 0;
        font-size: 0.8125rem;
    }

    .pg-pb-table th {
        padding: 0.45rem 0.75rem;
        text-align: left;
        font-size: 0.6875rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: #64748B;
        background: #F8FAFC;
        border-bottom: 1px solid #E2E8F0;
        white-space: nowrap;
        vertical-align: middle;
    }

    .pg-pb-table td {
        padding: 0.5rem 0.75rem;
        vertical-align: middle;
        border-bottom: 1px solid #F1F5F9;
        color: #0F172A;
        line-height: 1.35;
        height: 2.5rem;
    }

    .pg-pb-table th.num,
    .pg-pb-table td.num {
        text-align: right;
        font-variant-numeric: tabular-nums;
        white-space: nowrap;
    }

    .pg-pb-table tbody tr:last-child td {
        border-bottom: 0;
    }

    .pg-pb-empty {
        padding: 0.9rem 0.75rem;
        text-align: center;
        color: #94A3B8;
        font-size: 0.8125rem;
    }

    .pg-pb-total {
        font-weight: 700;
        background: #F8FAFC;
    }

    .pg-pb-muted {
        color: #64748B;
        font-weight: 500;
        font-size: 0.8125rem;
        line-height: 1.45;
        margin: 0;
    }

    @media (min-width: 1024px) {
        .pg-pb-top {
            grid-template-columns: minmax(0, 1.15fr) minmax(0, 0.85fr) minmax(0, 1fr);
        }

        .pg-pb-top--no-cost {
            grid-template-columns: minmax(0, 1.2fr) minmax(0, 0.8fr);
        }

        .pg-pb-table {
            min-width: 0;
        }
    }

    .dark .pg-pb-card {
        background: rgb(30 41 59);
        border-color: rgb(51 65 85);
    }

    .dark .pg-pb-card__head,
    .dark .pg-pb-table th,
    .dark .pg-pb-total {
        background: rgb(15 23 42);
        border-color: rgb(51 65 85);
    }

    .dark .pg-pb-dl dd,
    .dark .pg-pb-table td {
        color: #F8FAFC;
    }

    .dark .pg-pb-dl dt,
    .dark .pg-pb-card__title {
        color: #94A3B8;
    }
</style>
