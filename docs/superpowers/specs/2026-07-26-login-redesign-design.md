# Signed-out redesign — the curved hero

Date: 2026-07-26 · Status: approved by the owner

## Goal

Make the signed-out surface look like a considered product rather than a framework
default, without weakening anything that makes it safe to put in a hospital.

The current page is a straight vertical split: a flat `ground-deep` panel on the left, a
white form column on the right. It is correct and legible and reads as a template. The
redesign keeps every structural decision and replaces the flat panel with a full-bleed
illustration whose boundary with the form is an organic curve.

## Decisions taken during brainstorming

| Decision | Choice | Why |
| --- | --- | --- |
| Palette | **Keep teal** ("Monitor, in daylight") | The TowardPCC brand ADR chose Pulse Crimson, but adopting it here would leave the login crimson and every screen behind it teal. Internal consistency wins; the accent stays `channel`. |
| Motion | **Motivated depth** | Pointer-reactive layer drift, staged entry reveal, 150ms interactions. No loops, no scroll effects, no 3D engine. |
| Hero | **Generated illustration** | Owner has 1min.ai. Prompts are specified below; a CSS fallback ships first so the page is never broken while waiting on an asset. |
| Divider | **Organic curve** | The signature move in all three references the owner supplied. A straight split reads as a template; the curve carries the eye into the form. |

## Scope

`AuthLayout.vue` is shared by three pages — `Auth/Login.vue`, `Auth/Register.vue`,
`Auth/EmailOtpChallenge.vue`. Changing the layout changes all three, which is intended:
they should not diverge. Page-level files change only where they need the new spacing.

## Composition

**Desktop (`lg` and up).** One full-bleed stage. The illustration covers the viewport. An
opaque white form card occupies the right ~42%, and its left edge is an SVG curve that
sweeps from top to bottom, bulging into the illustration around the vertical centre. The
brand lockup sits top-left over the illustration; the strapline and unit chips sit
bottom-left.

**Below `lg`.** The curve rotates: the illustration becomes a top band roughly 30vh tall
with the curve sweeping down into the form beneath it. The form remains above the fold.

**DOM order is unchanged** — brand → form → orientation — so tab order still matches
visual order and the form is first on a phone. `min-h-dvh` stays (100vh on iOS sits
behind the URL bar and pushes the submit button off-screen).

## The curve

A single `<path>` in an inline SVG, `preserveAspectRatio="none"` so it stretches with the
viewport. It is **decorative**: `aria-hidden="true"`, no semantic role. The white fill of
the path IS the form surface — there is no separate card element behind it, which avoids
a seam where the two would meet at fractional pixel widths.

Two paths ship: one for the vertical (desktop) orientation, one for the horizontal
(mobile) orientation, swapped by a CSS media query rather than JavaScript, so the correct
one is present on first paint.

## Glass — and its limit

Frosted translucency appears on exactly two elements, both over the illustration and
both carrying non-critical text: the **brand lockup chip** and the **unit chips**.

It appears nowhere else. Specifically, the username field, the password field, error
messages and the submit button sit on the opaque white surface. Translucent panels put
text over an unpredictable image, and this is a system read at 3am on a shared ward
monitor of unknown quality. `tests/Feature/Build/TextContrastMeetsAaTest.php` exists
precisely to stop contrast regressions, and glass behind form text would fight it.

Chip text is white on a `rgba(255,255,255,0.16)` fill over the illustration. Because the
effective contrast depends on the image behind it, the chips also carry a semi-opaque
scrim so they do not rely on the illustration being dark in that region.

## The illustration

**Subject: the hospital at dawn.** Handover happens at 07:30 and 15:30 — first light is
the moment the system exists for, the night team handing to the day team. The imagery is
therefore motivated rather than decorative, which is the test the TowardPCC ADR sets.

**No people.** No children, patients, or staff. Generated people in a paediatric clinical
context go uncanny quickly, and an invented "patient" on the door of a real PHI system is
indefensible in a hospital review. The scene is a place.

### 1min.ai settings

- **Model:** Flux 1.1 Pro
- **Aspect ratio:** 16:9 landscape
- **Count:** 4

**Prompt**

> A serene modern hospital building at dawn, viewed from a landscaped garden path. Flat
> vector illustration, clean geometric shapes, subtle grain. Deep teal and petrol-blue
> palette (#04343a, #0d4a52, #0d7c8a) with soft warm amber light glowing from the windows.
> Pale mint and cool cyan sky with a low sun just breaking the horizon. Layered depth:
> silhouetted foliage foreground, building midground, soft hills far background. Calm,
> professional, hopeful. No people, no text, no logos, no medical equipment. Composition
> weighted to the left third: building and foliage on the left, open empty sky and calm
> gradient on the right two-thirds. Generous negative space upper-left for text.

**Negative prompt**

> people, faces, children, patients, doctors, nurses, text, letters, watermark, logo,
> medical equipment, hospital beds, syringes, clutter, harsh red, blood

**Alternative subject**, if the building reads too literal — swap the first sentence for
*"An abstract landscape of layered teal hills at dawn with a single soft sun, flat vector
illustration, no buildings"*. Quieter, ages better.

### Asset handling

Delivered as WebP with a JPEG fallback, `width`/`height` attributes set to reserve layout
space, `loading="eager"` and `fetchpriority="high"` since it is above the fold. Target
≤180KB for the WebP at 1920px wide; downscale rather than ship a heavier file.

**The CSS fallback ships first.** Before any generated asset exists, the illustration
region renders a layered SVG dawn composition built from token colours — horizon bands, a
sun disc, silhouetted hills. The page is complete and shippable without the generated
image.

### Dropping the image in

1. Save the chosen render as `public/img/auth-dawn.webp`.
2. Add one attribute in `resources/js/Pages/Auth/Login.vue` (and `Register.vue`,
   `EmailOtpChallenge.vue` if you want it everywhere):

   ```
   <AuthLayout hero-src="/img/auth-dawn.webp" …>
   ```

3. `npm run build`, commit, deploy.

Nothing else changes. `AuthHero` renders the `<img>` instead of the drawn scene; the
curve, the depth motion and every test behave identically.

## Motion

All motion is `transform`/`opacity` only, and **all of it collapses to static under
`prefers-reduced-motion: reduce`** — no exceptions.

| Move | Detail |
| --- | --- |
| Entry reveal | Lockup, heading, then form fields rise 12px and fade in, staggered ~60ms. Once, on mount. |
| Pointer depth | Three illustration layers translate up to 8px against pointer position, nearest layer moving most. Desktop and a fine pointer only — disabled under `(hover: none)`. |
| Focus | 150ms border-colour and ring transition on inputs. |
| Press | 1px translate on the submit button. |

Easing is a single voice: `cubic-bezier(0.22, 1, 0.36, 1)`. Durations are 150ms for
interactions, 400ms for reveals.

Pointer depth is driven by a `pointermove` listener on the hero element, throttled with
`requestAnimationFrame`, writing to CSS custom properties. It is removed on unmount.

**Carve-out.** The TowardPCC motion spec bans parallax. This is a scoped exception:
pointer-driven, not scroll-driven, bounded at 8px, signed-out pages only. Recorded here so
it is a decision rather than a drift.

## What does not change

- Every accessibility property already present: `for`/`id` label binding, single `<h1>`,
  `role="status"` / `role="alert"` on the flash regions, autocomplete hints.
- The `BrandMark` component — the handover-trace waveform is already the right mark.
- The teal token set. No new colours are introduced; the illustration's hexes live inside
  the generated asset and the fallback SVG, not in markup.
- No social login. Accounts are provisioned by a unit administrator and approval requires a
  verified email address; social auth would puncture that model.

## Files

| File | Change |
| --- | --- |
| `resources/js/Layouts/AuthLayout.vue` | Rewritten: curved stage, illustration slot, glass chips, entry reveal |
| `resources/js/Components/AuthHero.vue` | New — illustration + curve + pointer depth, self-contained |
| `resources/css/app.css` | Motion tokens (`--motion-ease`, durations); curve/glass utility classes |
| `resources/js/Pages/Auth/Login.vue` | Spacing only |
| `resources/js/Pages/Auth/Register.vue` | Spacing only |
| `resources/js/Pages/Auth/EmailOtpChallenge.vue` | Spacing only |
| `public/img/auth-dawn.webp` | The generated asset, when it arrives |

## Testing

- **Existing guards must stay green:** `CompiledCssIsLightOnlyTest` (no `dark:` utilities —
  a dark *surface* is fine, a dark *mode* is not) and `TextContrastMeetsAaTest`.
- **Vitest:** `AuthHero` renders the fallback when no image source is supplied; the
  pointer listener is removed on unmount.
- **Playwright:** sign-in still succeeds end to end; the form is above the fold at 390px
  wide; labels still resolve by accessible name.
- **New:** a reduced-motion test asserting no transform is applied when
  `prefers-reduced-motion: reduce` is emulated.

## Out of scope

Signed-in surfaces. The brand question for the wider TowardPCC platform (Pulse Crimson vs
teal) is not settled here and does not need to be for this work.

---

## What the build taught us (2026-07-26)

Three defects, none of which any unit test could have caught. Recorded because each is a
trap that would be walked into again.

**The form landed on the illustration at phone width.** The narrow curve yielded white
below 54% while the form column began around 19%, so `ink` text rendered directly on
`dawn-near`. Every test was green: the failure is purely positional, and only a real
layout engine can see it. Fixed by moving the curve's edge to 24–32% and giving the brand
panel a `min-h-[34vh]` that pins the form beneath it — the two numbers are coupled, which
is noted in both files.

**`backdrop-filter` inside `@layer components` was silently dropped.** The
`background-color` and `border` from the very same rule applied, so the chips looked
plausible while the frost never rendered. Tailwind's own layers outrank the components
layer. Moved unlayered, next to the `:focus-visible` rule that solved this exact problem
before.

**Then it still did not render.** Writing `-webkit-backdrop-filter` *after* the standard
property made the minifier keep the prefixed declaration and drop the standard one, and
Chrome reports `none` for the alias — so `getComputedStyle` said `none` while the bundle
contained a rule that looked correct. Unprefixed only; the build adds prefixes for the
configured targets.

The lesson worth keeping: **a visual change has to be looked at.** Three green suites and a
correct-looking stylesheet said this was finished, and it was broken on every phone.

## Regression cover added

- `tests/js/AuthHero.test.js` — fallback renders without an asset, the pointer listener is
  refused under reduced motion *and* on coarse pointers, and it is removed on unmount.
- `tests/e2e/auth-hero.spec.js` — the geometry invariants, plus a **positive control**
  proving the scene really does lean toward the pointer. Without it, "nothing moved under
  reduced motion" would pass just as happily if nothing ever moved at all.
