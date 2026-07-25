# 7. Rich text (the critical bug — clone the fix)

- `RichTextEditor.vue` cloned from the reference: `document.execCommand('styleWithCSS', …)` is set **per command** — ON only for `foreColor`/`hiliteColor` (emitting allow-listed `span[style]`), OFF for bold/italic/underline/lists (emitting `<b>/<i>/<u>/<ul>/<ol>` tags). The legacy global `styleWithCSS(true)` emitted `font-style`/`text-decoration-line` CSS that the sanitiser discards — the production silent-data-loss bug. Never copy the legacy toolbar JS.
- Sanitisation **on write** via the `SanitizedHtml` Eloquent cast on disease/details/plan/nevent → `RichTextSanitizer` (HTMLPurifier). Because it lives on the model, it covers controller writes, new-day copies, and legacy import identically.
- Allow-list, exactly: `p,br,b[style],strong[style],i[style],em[style],u[style],ul,ol,li[style],span[style],div[style],h1,h2,h3,font[color]`; `CSS.AllowedProperties = color,background-color,font-weight,text-decoration`.
- **Proof obligations (ported tests):** verbatim Chrome execCommand markup round-trips (bold, italic, underline, both list types, colour, highlight); colour applied over bold/underline survives; `<script>`, event handlers, `expression()`, `behavior:url()`, `javascript:` URIs, `font-style`, and `position:fixed` are stripped; a Playwright journey proves bold/italic/underline/list/colour-on-bold survive a save **and reload** in a real browser.

---
