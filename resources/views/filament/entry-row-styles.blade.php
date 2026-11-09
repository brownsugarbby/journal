{{-- Panel-wide styles. Jurnal / Kas Pusat entry row: cells keep a readable
     width and wrap to a second line instead of squeezing; labels stay on one
     line so the inputs and the Simpan button line up. --}}
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

    /* Dashboard stat cards: big rupiah figures shrink with the card instead
       of running past its edge. */
    .fi-wi-stats-overview-stat-value {
        font-size: clamp(1.25rem, 2.4vw, 1.875rem);
        overflow-wrap: anywhere;
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

    /* Phones and small tablets: keep the top menu (scrolls sideways) next
       to the search box instead of Filament's ☰ drawer. */
    @media (max-width: 63.99rem) {
        .fi-topbar .fi-topbar-open-sidebar-btn,
        .fi-topbar .fi-topbar-close-sidebar-btn {
            display: none !important;
        }

        .fi-topbar .fi-topbar-nav-groups {
            display: flex;
            flex: 1 1 auto;
            flex-wrap: nowrap;
            min-width: 0;
            margin-inline: 0 0.5rem;
            column-gap: 0.25rem;
            overflow-x: auto;
            scrollbar-width: none;
        }

        .fi-topbar .fi-topbar-nav-groups::-webkit-scrollbar {
            display: none;
        }

        .fi-topbar {
            padding-inline: 0.75rem;
        }

        .fi-topbar .fi-topbar-nav-groups {
            column-gap: 0;
        }

        .fi-topbar .fi-topbar-nav-groups .fi-topbar-item-btn {
            white-space: nowrap;
            padding-inline: 0.375rem;
        }

        .fi-topbar .fi-topbar-nav-groups .fi-topbar-item-label {
            font-size: 0.8125rem;
        }

        .fi-topbar .fi-topbar-nav-groups .fi-topbar-group-toggle-icon {
            display: none;
        }

        .fi-topbar .fi-topbar-end {
            flex: 0 0 auto;
            column-gap: 0.5rem;
        }

        /* Search is just its magnifier, leaving room for the menu; tapped, it
           opens over the whole top bar like a popup, results below it. */
        .fi-topbar .fi-global-search-field {
            width: 2.6rem;
        }

        /* The magnifier lies over the input, so a tap anywhere focuses it. */
        .fi-topbar .fi-global-search-field .fi-input-wrp {
            position: relative;
        }

        .fi-topbar .fi-global-search-field .fi-input-wrp-prefix {
            position: absolute;
            inset-block: 0;
            inset-inline-start: 0;
            pointer-events: none;
        }

        .fi-topbar .fi-global-search-field input {
            padding-inline-start: 2.4rem;
        }

        .fi-topbar .fi-global-search:focus-within {
            position: fixed;
            inset-inline: 0;
            top: 0;
            z-index: 40;
            padding: 0.75rem;
            background: var(--color-white, #fff);
            box-shadow: 0 8px 24px rgb(0 0 0 / 0.12);
        }

        .dark .fi-topbar .fi-global-search:focus-within {
            background: var(--gray-900);
        }

        .fi-topbar .fi-global-search:focus-within .fi-global-search-field {
            width: 100%;
        }

        .fi-topbar .fi-global-search:focus-within .fi-global-search-results-ctn {
            position: static;
            width: 100%;
            max-width: none;
            margin-top: 0.5rem;
        }
    }
</style>
