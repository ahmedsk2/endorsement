# P0b — Bounded Custom Fields Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Let a department define its own handover-sheet fields as data (design §6.2, "Ceiling 2") — per-unit `{key, label, type, required}` definitions, values stored in one encrypted JSON column, rendered and validated generically.

**Architecture:** Two new pieces that **coexist with** the existing per-unit identity columns rather than replacing them. `units.extra_row_fields` keeps driving the existing named, individually-encrypted columns (`dob`, `age`, `ward_unit`); a new `unit_field_definitions` table adds *custom* fields on top, whose values live in a new `handovers.extra_fields` TEXT column behind an `EncryptedJson` cast. The four paediatric units get zero definitions, so nothing about them changes.

**Tech Stack:** Laravel 13, Eloquent, PHPUnit (SQLite), Inertia + Vue 3, Vitest.

**Scope:** Second of four P0 plans. P0a (units as configuration) is merged. No admin UI for managing definitions — they are seedable/insertable, which is still zero *code* for a new department; the management screen belongs with the units settings screen in a later phase. Note it, don't build it.

---

## Four findings from reconnaissance that shape this plan

Read these before any task. Each is a bug that would otherwise ship.

1. **Per-field autosave + a whole-column JSON map is a data-loss combination — the highest risk here.**
   `validateRow()` uses `sometimes` rules, so an autosave PATCHes only the field that lost focus, and `updateRow()` (`EndorsementController.php:661-668`) writes what came back. Because the whole map is one column, saving `{'weight': '3.2'}` **replaces the map and wipes every other custom field on that row.** The controller must merge into the currently-decrypted map before assigning. The cast cannot protect against this and no existing test would catch it.

2. **The column must be `text`, never `json`.** `units.extra_row_fields` is a `json` column, and pattern-matching on it here fails **only in production**: the stored value is base64 ciphertext, which MySQL's JSON type rejects as "Invalid JSON text", while SQLite maps `json` to TEXT and every test passes.

3. **`recordRevisions()` will 500 on every save** if `extra_fields` is added to `HandoverRevision::TRACKED` — `EndorsementController.php:983-984` does `(string) $before`, and an array raises "Array to string conversion", which `HandleExceptions` promotes to a thrown `ErrorException`.

4. **`EncryptedJson` must NOT copy `ExtraRowFields`' `ALLOWED` allow-list.** That list exists because `ExtraRowFields`' output becomes *model attribute names* in `validateRow()`, a mass-assignment vector. `extra_fields`' keys are map keys inside one column and can never become attribute names. Worse, an allow-list keyed on `unit_field_definitions` would be **actively destructive**: retiring a field definition would silently delete that value from every historical row on the next save. Clinical values must survive the removal of their definition — they simply stop rendering. Bound by byte size and value type, never key identity.

---

### Task 1: The `unit_field_definitions` table

**Files:** create `database/migrations/2026_08_09_120001_create_unit_field_definitions_table.php`, `app/Models/UnitFieldDefinition.php`; modify `app/Models/Unit.php`; create `tests/Feature/Units/UnitFieldDefinitionTest.php`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Units/UnitFieldDefinitionTest.php` — `RefreshDatabase`, seed `ReferenceSeeder`. Assert:
- A definition can be attached to a unit and read back via `$unit->fieldDefinitions` ordered by `display_order` then `id`.
- `active` defaults to `true` (unlike `units.active`; a definition you bothered to create is meant to be used, and it is inert until a value references it).
- The `(unit_id, key)` pair is unique — inserting a duplicate throws.
- `$unit->fieldDefinitions` excludes inactive definitions when read through the `active()` scope, and the four seeded paediatric units have **zero** definitions.

- [ ] **Step 2: Run it, watch it fail** — `php artisan test --filter UnitFieldDefinitionTest | Select-Object -Last 15`

- [ ] **Step 3: Migration**

```php
Schema::create('unit_field_definitions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
    // The map key inside handovers.extra_fields. Immutable once values exist:
    // renaming it orphans every stored value, so the admin path must forbid it.
    $table->string('key', 64);
    $table->string('label');
    $table->string('type', 16)->default('text');   // text | date | select
    $table->json('options')->nullable();           // select only: list<string>
    $table->boolean('required')->default(false);
    $table->unsignedSmallInteger('display_order')->default(1000);
    $table->boolean('active')->default(true);
    $table->timestamps();

    $table->unique(['unit_id', 'key']);
    $table->index(['unit_id', 'display_order']);
});
```

No `institution_id` — the unit already carries it, and a definition is meaningless outside its unit.

- [ ] **Step 4: Model + relation**

`UnitFieldDefinition` with `$fillable`, casts (`options` => array, `required`/`active` => boolean, `display_order` => integer), `scopeActive`, `scopeOrdered` (mirror `Unit`'s). Add to `Unit`:

```php
/** @return HasMany<UnitFieldDefinition, $this> */
public function fieldDefinitions(): HasMany
{
    return $this->hasMany(UnitFieldDefinition::class)->active()->ordered();
}
```

- [ ] **Step 5: Green, then commit** — `test: unit field definitions`

---

### Task 2: The `EncryptedJson` cast

**Files:** create `app/Casts/EncryptedJson.php`, `tests/Unit/Casts/EncryptedJsonTest.php`; modify `tests/Feature/Security/WrongKeyProtectionTest.php`.

This is the most delicate task. **Read `app/Casts/EncryptedString.php`, `app/Casts/SanitizedHtml.php`, `app/Casts/ExtraRowFields.php` and `app/Casts/Concerns/DetectsForeignCiphertext.php` in full before writing anything** — the conventions are load-bearing and argued, not decorative.

- [ ] **Step 1: Write the failing tests first**

`tests/Unit/Casts/EncryptedJsonTest.php` extending **`Tests\TestCase`** (the Crypt facade is required — do *not* copy `ExtraRowFieldsTest`'s bare `PHPUnit\Framework\TestCase`). Class docblock in the `WrongKeyProtectionTest` style, explaining the threat. Cases:

1. a map round-trips
2. `null` and `''` yield `[]`
3. an empty map stores as `null`
4. legacy plaintext JSON is still readable
5. legacy plaintext that is not JSON yields `[]`
6. a JSON array yields `[]`
7. a JSON scalar yields `[]`
8. a nested value is dropped on read, a sibling scalar survives
9. a nested value is **rejected** on write (`InvalidArgumentException`; assert the message contains neither the value nor any patient text)
10. the reserved `__unreadable` key cannot be stored
11. the reserved key is stripped when already in the database
12. ciphertext from another key reads as the unreadable marker (assert the raw ciphertext does **not** appear in the output)
13. overwriting a value encrypted under another key is refused
14. **clearing** a value encrypted under another key is *also* refused — this pins guard-before-short-circuit ordering
15. a map over the byte ceiling is refused
16. values normalize to strings
17. keys store in canonical order

Build foreign ciphertext exactly as `WrongKeyProtectionTest` does: `new \Illuminate\Encryption\Encrypter(random_bytes(32), 'aes-256-cbc')`.

- [ ] **Step 2: Run, watch fail**

- [ ] **Step 3: Implement**

`@implements CastsAttributes<array<string, string|null>, string|null>`. `get()` returns `array` — **always an array, never null**, so consumers can `foreach` safely.

```php
public const UNREADABLE_KEY = '__unreadable';
public const MAX_BYTES = 32_000;
```

`get()`: `null`/`''` → `[]`; `try { Crypt::decryptString((string) $value) } catch (\Throwable)` → if `looksEncrypted($value)` return `[self::UNREADABLE_KEY => $this->unreadableMarker()]`, else treat the raw value as legacy plaintext; then `json_decode($json, true, 16)`; non-array → `[]`; then normalize (drop non-string/empty keys and the sentinel, drop non-scalar values, cast scalars to string, booleans to `'1'`/`'0'`, preserve nulls).

`set()`: **`assertNotOverwritingForeignCiphertext($key, $attributes)` as the very first statement, before any null check** — otherwise clearing the column silently destroys ciphertext that is still recoverable. Then `null`/`[]` → `null`; non-array → `InvalidArgumentException` naming only the key and `get_debug_type()`; normalize (throwing on non-scalar values rather than skipping — a nested value here is a controller bug and dropping it would lose clinical text); `ksort()` for stable plaintext; `json_encode(..., JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR)`; refuse over `MAX_BYTES`; `Crypt::encryptString()`.

`MAX_BYTES` note for the docblock: Laravel base64s the AES output *and* base64s the whole envelope, so growth is ≈1.78× + ~170 bytes — **not** the ~1.4× the `2026_07_25_150001` migration docblock claims. 32 KB of JSON becomes ~57 KB of ciphertext, inside MySQL TEXT's 65,535 with headroom.

Class docblock must argue, per the directory's convention, and must state: why the three Laravel built-ins (`encrypted:array`, `AsEncryptedArrayObject`, `AsEncryptedCollection`) are unusable — all throw on any value they did not write, and this column is read once per row on a census page, so one restored or hand-inserted row would 500 the whole sheet; that extra fields are **not searchable or indexable**; that degradation is **all-or-nothing** (unlike per-field columns, one bad ciphertext hides every custom field on the row, which is what the sentinel exists to surface); that values are **plain text and never purified**, so every consumer must escape them — `{{ }}` in Vue, never `v-html`, and if a `richtext` type is ever added this cast must gain `RichTextSanitizer::clean()` on write *and* on the legacy-plaintext read fallback; and the mirror of `ExtraRowFields`' note — **do not add an `ALLOWED`-style key allow-list here** (finding 4 above).

Two consumer hazards to document in the docblock: Laravel caches class-cast results only when `get()` returns an object, so an array return re-decrypts on **every** access — read the map into a local once. And `$handover->extra_fields['x'] = 'y'` does not work (indirect modification of an overloaded property, promoted to `ErrorException` by `HandleExceptions`); callers must read, mutate, reassign.

- [ ] **Step 4: Also close the `EncryptedDateTime` gap**

`app/Casts/EncryptedDateTime.php` does not use `DetectsForeignCiphertext` at all — no guard in `set()`, no marker in `get()`. For a `dob` encrypted under a rotated key the clinician sees a blank date and the next save silently overwrites the foreign ciphertext. `dob` is a direct identifier by that cast's own docblock. Add the trait, the guard as `set()`'s first statement, and the marker path in `get()`'s catch — matching `EncryptedString`. Add the corresponding cases to `tests/Feature/Security/WrongKeyProtectionTest.php`, which is the home of the wrong-key contract and currently covers only `EncryptedString` and `SanitizedHtml`.

- [ ] **Step 5: Green, full suite, commit** — `feat: an encrypted JSON cast for per-unit custom fields`

---

### Task 3: The `handovers.extra_fields` column

**Files:** create `database/migrations/2026_08_09_120002_add_extra_fields_to_handovers.php`; modify `app/Models/Handover.php`.

- [ ] **Step 1** — migration, additive and nullable, per-column guard:

```php
Schema::table('handovers', function (Blueprint $table) {
    if (! Schema::hasColumn('handovers', 'extra_fields')) {
        // TEXT, not json: the stored value is base64 ciphertext, which MySQL's JSON
        // type rejects as "Invalid JSON text". SQLite maps json to TEXT, so a json()
        // column here would pass every test and fail only in production.
        $table->text('extra_fields')->nullable()->after('nevent');
    }
});
```

- [ ] **Step 2** — add `'extra_fields'` to `Handover::$fillable` and to `casts()` as `\App\Casts\EncryptedJson::class`, with an inline comment in the style of the existing encrypted-identifier block naming the compliance layer and the consequence.

- [ ] **Step 3** — green, commit.

---

### Task 4: Server-side read, validate and **merge**

**Files:** modify `app/Http/Controllers/EndorsementController.php`; modify `tests/Feature/Endorsement/EndorsementTest.php` (additions only).

- [ ] **Step 1: Write the failing tests first.** The merge test is the important one:

```php
public function test_saving_one_custom_field_does_not_wipe_the_others(): void
{
    // Two definitions on a unit; set both; then PATCH only one, as autosave does.
    // Assert the untouched value survives. Without merge-on-write it is silently lost.
}
```

Plus: a required definition rejects an empty value; a `select` definition rejects a value outside its options; a `date` definition rejects a non-date; an unknown key in the payload is ignored rather than stored; a value for an **inactive** definition is not rendered but is **not deleted** from storage.

- [ ] **Step 2: `unitPayload()`** gains the unit's field definitions so the client can render generically — `'field_definitions' => $unit->fieldDefinitions->map(fn ($d) => ['key' => $d->key, 'label' => $d->label, 'type' => $d->type, 'options' => $d->options, 'required' => $d->required])->all()`.

- [ ] **Step 3: `rowsFor()`** emits `'extra_fields' => (object) $h->extra_fields` — cast to object so the JSON shape is a stable `{}` rather than flipping between `{}` and `[]`, which would break client code assuming an object.

- [ ] **Step 4: `validateRow()`** additionally builds rules from the unit's definitions, namespaced under `extra_fields.*` so they can never collide with a real column name (this is what keeps finding 4's mass-assignment concern inapplicable): `required|nullable`, `date` for `type=date`, `in:` for `type=select`, `string|max:500` for `type=text`.

- [ ] **Step 5: `storeRow()`/`updateRow()` — the merge.** When the payload carries `extra_fields`, merge into the currently-stored map rather than replacing it:

```php
// Autosave PATCHes ONE field at a time, and the whole map is one column — so
// assigning the payload directly would wipe every other custom field on the row.
if (array_key_exists('extra_fields', $data)) {
    $data['extra_fields'] = array_merge($handover->extra_fields, $data['extra_fields']);
}
```

- [ ] **Step 6: Revisions.** Do **not** add `extra_fields` to `HandoverRevision::TRACKED` as-is — `recordRevisions()` casts to `(string)` and would fatal on an array. Record per-subfield instead: `field = "extra_fields.{$key}"` with scalar old/new values, so an edit still has a before-image. If that proves larger than expected, exclude it and say so in the report — but state the clinical cost, since the revision table exists precisely to provide that before-image.

- [ ] **Step 7: The wrong-key refusal must not be a 500.** `assertNotOverwritingForeignCiphertext()` throws `RuntimeException` and nothing catches it, so an autosave against a wrong-key row returns a 500 with the reason in the log. The project rule is that autosave reflects the server response. Catch it in `updateRow()` and convert to a `ValidationException` carrying the same message.

- [ ] **Step 8: Green, full suite, commit.**

---

### Task 5: Generic rendering

**Files:** modify `resources/js/Pages/Endorsement/Sheet.vue`, `resources/js/Pages/Endorsement/Print.vue`; modify/extend `tests/js/EndorsementSheet.test.js`, `tests/js/EndorsementPrint.test.js`.

**Every change in `Sheet.vue` must be made twice** — it renders the census as mobile cards (`md:hidden`, ~lines 423-468) and a desktop table (~lines 471-530), each with its own `v-for`.

- [ ] **Step 1: Vitest first.** Assert: a unit with definitions renders one input per definition in both layouts; values bind from `row.extra_fields[key]`; a `select` renders its options; changing one field PATCHes only that field; the `__unreadable` sentinel renders as a visible row-level warning; and values render via interpolation, **never `v-html`**.

- [ ] **Step 2: `Sheet.vue`.** Today `extraFields` (lines ~45-50) derives `{key,label,type}` from bare column names with hardcoded conditionals (`key === 'dob' ? 'DOB' : ...`). Keep that computed for the existing named identity columns, and add a **second** list from `unit.profile.field_definitions` for custom fields, rendered after the existing ones in both layouts. Custom fields read `row.extra_fields[key]` and save via a payload shaped `{extra_fields: {[key]: value}}` through the existing `saveField`/`@change` path — do not invent a second save mechanism.

- [ ] **Step 3: `Print.vue`.** Same split, preserving the A4 column-width and page-break behaviour. A unit with many definitions can overflow the page: cap what print renders and say so, rather than silently producing an unusable sheet.

- [ ] **Step 4: The sentinel.** If `row.extra_fields.__unreadable` is present, render a visible row-level warning in both the sheet and print. A renderer keyed strictly on definitions would drop it and show a clean, silently incomplete clinical sheet — which is the whole reason the sentinel exists.

- [ ] **Step 5: `npm run test` and `npm run build` green, commit.**

---

### Task 6: Verify the legacy import is unaffected, and document

**Files:** modify `docs/spec/03-unit-model.md`, `CLAUDE.md`, `docs/superpowers/specs/2026-08-08-munawib-endorsement-integration-design.md`.

- [ ] **Step 1** — confirm `app/Support/LegacyImport.php` maps legacy columns onto the **named** columns only and never needs `extra_fields` (legacy data predates custom fields). State the finding; change nothing unless it is wrong.

- [ ] **Step 2** — document in `docs/spec/03-unit-model.md`: the two-tier model (named identity columns via `extra_row_fields`; custom fields via `unit_field_definitions` + encrypted `extra_fields`), that custom fields are not searchable or indexable, that degradation is all-or-nothing per row, and that retiring a definition hides values without deleting them.

- [ ] **Step 3** — add to `CLAUDE.md`'s rules: custom-field values are plain text, never purified, so every consumer escapes them; and never add a key allow-list to `EncryptedJson`.

- [ ] **Step 4** — record in the design doc that P0b ships without a definitions admin UI, and that it belongs with the units settings screen.

- [ ] **Step 5** — full suite + build green, commit.

---

## Definition of done

- A unit with field definitions renders, validates, saves and prints them; the four paediatric units are unchanged.
- Saving one custom field provably does not wipe the others.
- `extra_fields` is `text`, not `json`.
- A row whose `extra_fields` is encrypted under a foreign key still renders the sheet, shows a visible warning, and refuses to be overwritten or cleared.
- `EncryptedDateTime` has the same wrong-key protection as the other encrypted casts.
- `php artisan test`, `npm run test` and `npm run build` all green.
