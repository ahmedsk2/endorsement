# PICU Registry — design system: "Monitor, in daylight"

**Direction.** The instruments already in a PICU (patient monitors) have a visual language the staff
read fluently: cool channel traces, tiny uppercase channel tags, monospaced numerals. This interface
is that instrument rendered on a light clinical ground — not a generic admin template.

**LIGHT THEME ONLY.** There is no dark variant and none should be added: a handover screen must look
identical to the day and night staff reading it. Any `dark:` utility is a bug.

Tokens live in `laravel/resources/css/app.css` (`@theme`). Use the semantic class names below —
never raw Tailwind palette classes (`gray-*`, `blue-*`, `slate-*`, …) and never hex values in markup.

## Colour tokens

| Class | Hex | Use |
|---|---|---|
| `ink` | `#0b2e33` | headings, primary text, key values |
| `body` | `#2c4f53` | body copy |
| `muted` | `#6b8b8e` | secondary/meta text, channel tags, placeholders |
| `line` | `#cbdedc` | hairline borders |
| `line-soft` | `#e0ecea` | subtle dividers, table stripes |
| `ground` | `#edf4f3` | page ground |
| `ground-deep` | `#e2eeec` | sidebar, inset surfaces, table headers |
| `panel` | `#ffffff` | cards and data surfaces |
| `channel` | `#0d7c8a` | primary fills, brand mark, bars |
| `channel-ink` | `#0a6b75` | primary **text** and links (AA on white) |
| `channel-soft` | `#dff0f1` | active nav wash, chips, selected rows |
| `ok` / `ok-soft` | `#0f8a6a` / `#dcf1ea` | success, "done" badges, computed scores |
| `caution` / `caution-soft` | `#b7791f` / `#fbf0dc` | warnings, `[NEEDS SIGN-OFF]`, data gaps |
| `critical` / `critical-soft` | `#b3261e` / `#fbe4e2` | destructive actions, errors, mortality |
| `unit-picu` | `#0d7c8a` | the unit channel hue (G1 — the registry is PICU-only, so this is the only one) |

## Type

- **UI:** IBM Plex Sans (`font-sans`, the default).
- **Data:** IBM Plex Mono (`font-mono`) — every clinical numeral, MRN, dose, score, timestamp and ID.
  Use the `.readout` class. Tabular figures stop a dose being misread; this is functional.
- **Micro-labels:** `.channel-tag` — mono, 10px, uppercase, `0.08em` tracking, `muted`. Use for field
  labels, eyebrows and table column headers (replaces `text-xs uppercase text-gray-400` patterns).
- Scale: page title `text-xl font-semibold`, section `text-base font-semibold`, body `text-sm`,
  meta `text-xs`. Headings are `ink`.

## Signature — the channel bar

`.channel-bar` puts a 3px left edge on a card/panel/active nav item. It **encodes meaning**, never
decorates: on the patient board it carries the unit (`.channel-bar-picu` — the only unit variant,
since G1 made the registry PICU-only); elsewhere `.channel-bar-ok` / `.channel-bar-critical` carry
status. Anything without a unit hue uses the plain `.channel-bar` default. One bar per element; if
nothing meaningful is encoded, omit it.

## Conversion map (old → new)

Apply mechanically; do not invent alternatives.

| Old | New |
|---|---|
| any `dark:*` utility | **delete** |
| `bg-gray-50` | `bg-ground` |
| `bg-gray-100` | `bg-ground-deep` |
| `bg-white` | `bg-panel` |
| `border-gray-200` / `-300` | `border-line` |
| `divide-gray-100` / `-200` | `divide-line-soft` |
| `text-gray-400` / `-500` | `text-muted` |
| `text-gray-600` / `-700` | `text-body` |
| `text-gray-800` / `-900` | `text-ink` |
| `bg-blue-600` / `-700` (fills, buttons) | `bg-channel` (hover `bg-channel-ink`) |
| `text-blue-600` / `-700` (links) | `text-channel-ink` |
| `bg-blue-50` / `-100` | `bg-channel-soft` |
| `border-blue-200` / `-800` | `border-channel` |
| `green-*` / `emerald-*` | `ok` family (`bg-ok-soft`, `text-ok`, `bg-ok`) |
| `amber-*` / `yellow-*` | `caution` family |
| `red-*` | `critical` family |
| `indigo-*` | `channel` family |

Radii: prefer `rounded-md` (instrument feel) over `rounded-xl`/`rounded-2xl`. Keep shadows minimal —
`border border-line` on `bg-panel` reads cleaner than a shadow at this density.

## Out of scope — do not restyle

- `resources/views/print/emergency-medications.blade.php`
- `resources/views/print/nursing-daily.blade.php`
- `resources/js/Pages/Endorsement/Print.vue`

These are **verbatim legacy print templates / print-fidelity contracts**. Their appearance is a
requirement, not a style choice. Leave their markup and CSS untouched.

## Accessibility floor

Visible keyboard focus (`:focus-visible` outline in `channel`), reduced motion honoured, text
contrast AA — use `channel-ink` (not `channel`) for text on white, and `ink`/`body` for copy.

## Unit hues (this project)

Four first-class units, each with a channel hue for `.channel-bar-*` edges. All four sit
in the cool/plum family so none collides with the ok / caution / critical signal colours.

| Unit | Token | Value | Bar class |
|------|-------|-------|-----------|
| PICU | `--color-unit-picu` | `#0d7c8a` (teal — the primary channel) | `.channel-bar-picu` |
| NICU | `--color-unit-nicu` | `#5b6bbf` (indigo) | `.channel-bar-nicu` |
| SCBU | `--color-unit-scbu` | `#8a5ba8` (violet) | `.channel-bar-scbu` |
| WARD | `--color-unit-ward` | `#9c5470` (plum) | `.channel-bar-ward` |

A channel bar encodes ONE meaning per element — unit identity on unit-scoped surfaces
(chooser cards, day index), status (`-ok/-caution/-critical`) on status surfaces. Never both.
