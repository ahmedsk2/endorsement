# 9. Design system and UI

- "Monitor, in daylight" tokens copied verbatim from `laravel/resources/css/app.css` + `docs/DESIGN-TOKENS.md`: **light theme only** (any `dark:` utility is a bug — enforced by the ported compiled-CSS build test); semantic classes only (`.readout` for every clinical numeral/date/MRN, `.channel-tag` for labels, `.channel-bar` encoding meaning only); IBM Plex Sans/Mono; no raw Tailwind palette classes or hex in markup; borders over shadows; unlayered `:focus-visible` rule kept outside `@layer`.
- Three new unit hues minted (`unit-nicu`, `unit-scbu`, `unit-ward`) with matching `.channel-bar-*` variants, chosen within the token family for AA contrast.
- **Mobile-first from the first sheet build (not retrofitted):** desktop keeps the table; below ~768 px the sheet renders a card per patient (identity line + four stacked rich-text sections) with identical save-on-blur semantics. Touch: ≥44 px tap targets, ≥16 px input font (prevents iOS zoom), formatting toolbar docks above the keyboard on focus, no horizontal scrolling.

---
