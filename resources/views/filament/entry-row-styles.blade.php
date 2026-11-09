{{-- Jurnal / Kas Pusat entry row: cells keep a readable width and wrap to a
     second line instead of squeezing; labels stay on one line so the inputs
     and the Simpan button line up. --}}
<style>
    .fi-entry-row > .fi-grid {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-end;
        gap: 1rem 0.75rem;
    }

    .fi-entry-row > .fi-grid > .fi-grid-col {
        flex: 1 1 9rem;
        min-width: 9rem;
    }

    .fi-entry-row > .fi-grid > .fi-grid-col:has(input[type="date"]) {
        flex: 0 0 10.5rem;
    }

    .fi-entry-row > .fi-grid > .fi-grid-col:has(.fi-entry-wide) {
        flex: 2 1 13rem;
        min-width: 13rem;
    }

    .fi-entry-row > .fi-grid > .fi-grid-col:has(.fi-sc-actions) {
        flex: 0 0 auto;
        min-width: 0;
    }

    .fi-entry-row .fi-fo-field-label-content {
        white-space: nowrap;
    }

    .fi-opening-row {
        display: flex;
        flex-wrap: wrap;
        align-items: baseline;
        gap: 0.25rem 0.75rem;
        padding: 0.625rem 1.5rem;
        font-size: 0.875rem;
        background: var(--gray-50);
        border-top: 1px solid var(--gray-200);
    }

    .fi-opening-row strong {
        margin-left: auto;
    }

    .fi-opening-row-date {
        color: var(--gray-500);
    }

    .dark .fi-opening-row {
        background: rgb(255 255 255 / 0.05);
        border-top-color: rgb(255 255 255 / 0.1);
    }
</style>
