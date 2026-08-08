# P0a — Units as Configuration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move every per-unit difference out of the hardcoded `App\Support\UnitProfile` registry and into the `units` table, so a department is defined by data rather than by code — with the four paediatric units behaving identically afterwards.

**Architecture:** `UnitProfile` survives as the *shape* — the single value object every surface reads, and the client contract sent over Inertia — but is built from a `Unit` row instead of a static array. The `units` table gains nine configuration columns, backfilled in the migration so the owner's existing production database arrives correct without a seeder run, and seeded for fresh installs. `UnitProfile::codes()` becomes `Unit::codes()`, DB-backed and ordered by a new `display_order`, with a new `active` flag giving retired units the 404 the spec already requires.

**Tech Stack:** Laravel 13, Eloquent, PHPUnit (SQLite in-memory), Inertia + Vue 3.

**Scope:** This is the first of four plans making up P0 (design doc §13). The others are P0b (bounded custom fields), P0c (identity & auth lifecycle), P0d (tenancy & provisioning). This plan ships working software on its own: behaviour unchanged, the four units become data.

**Two corrections to the design doc, found while reading the code:**
1. §6.1 refers to `EndorsementController::UNIT_CODES`. No such constant exists — the controller calls `UnitProfile::codes()` in nine places. That is what this plan replaces.
2. Tests run under `RefreshDatabase` with explicit seeding (`tests/Feature/Endorsement/EndorsementTest.php:26-30`), so the `units` table is empty until `ReferenceSeeder` runs. The migration's backfill is therefore a **production-only** path and is unexercised by the suite; Task 1 Step 8 verifies it by hand instead.

---

### Task 1: Units carry their own configuration

**Files:**
- Create: `database/migrations/2026_08_08_120001_add_configuration_to_units.php`
- Modify: `database/seeders/ReferenceSeeder.php:41-51`
- Test: `tests/Feature/Units/UnitConfigurationTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Units/UnitConfigurationTest.php`. Note the `setUp` mirrors `EndorsementTest` — `RefreshDatabase` leaves no units behind, so the seeder must run:

```php
<?php

namespace Tests\Feature\Units;

use App\Models\Unit;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The units table is now the SINGLE source of per-unit variation (design §6.1). These tests
 * pin the four seeded paediatric profiles, so the move off the hardcoded UnitProfile registry
 * is provably behaviour-preserving.
 */
class UnitConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
    }

    public function test_picu_shows_no_extra_identity_columns(): void
    {
        $picu = Unit::where('code', 'PICU')->firstOrFail();

        $this->assertSame([], $picu->extra_row_fields);
        $this->assertSame('Bed', $picu->bed_label);
        $this->assertTrue($picu->consultant_pair);
        $this->assertSame('Consultant covering', $picu->consultant_by_label);
        $this->assertSame('channel-bar-picu', $picu->bar_class);
        $this->assertSame('Plan Of Care', $picu->print_plan_label);
        $this->assertSame('New events', $picu->print_narrative_label);
        $this->assertTrue($picu->active);
    }

    public function test_nicu_and_scbu_add_dob(): void
    {
        foreach (['NICU', 'SCBU'] as $code) {
            $unit = Unit::where('code', $code)->firstOrFail();

            $this->assertSame(['dob'], $unit->extra_row_fields, $code);
            $this->assertSame('Bed', $unit->bed_label, $code);
            $this->assertTrue($unit->consultant_pair, $code);
            $this->assertSame('Plan Of Care', $unit->print_plan_label, $code);
            $this->assertSame('To be followed', $unit->print_narrative_label, $code);
        }

        $this->assertSame('channel-bar-nicu', Unit::where('code', 'NICU')->value('bar_class'));
        $this->assertSame('channel-bar-scbu', Unit::where('code', 'SCBU')->value('bar_class'));
    }

    /** Ruling 5 — WARD has ONE consultant field, labelled "Consultant Oncall". */
    public function test_ward_carries_its_own_shape(): void
    {
        $ward = Unit::where('code', 'WARD')->firstOrFail();

        $this->assertSame(['age', 'ward_unit'], $ward->extra_row_fields);
        $this->assertSame('Room', $ward->bed_label);
        $this->assertFalse($ward->consultant_pair);
        $this->assertSame('Consultant Oncall', $ward->consultant_by_label);
        $this->assertSame('channel-bar-ward', $ward->bar_class);
        $this->assertSame('Management', $ward->print_plan_label);
        $this->assertSame('To be followed', $ward->print_narrative_label);
    }

    public function test_display_order_matches_the_historical_code_order(): void
    {
        $this->assertSame(
            ['PICU', 'NICU', 'SCBU', 'WARD'],
            Unit::orderBy('display_order')->pluck('code')->all()
        );
    }

    /**
     * A re-seed refreshes `name` only. The profile columns are seeded once and then belong to
     * the department — an admin's configuration must never be silently reverted.
     */
    public function test_reseeding_preserves_admin_configuration(): void
    {
        Unit::where('code', 'PICU')->update(['bed_label' => 'Cot', 'print_plan_label' => 'Plan']);

        $this->seed(ReferenceSeeder::class);

        $picu = Unit::where('code', 'PICU')->firstOrFail();
        $this->assertSame('Cot', $picu->bed_label);
        $this->assertSame('Plan', $picu->print_plan_label);
        $this->assertSame('Pediatric Intensive Care Unit', $picu->name);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```powershell
php artisan test --filter UnitConfigurationTest | Select-Object -Last 15
```

Expected: FAIL — `no such column: extra_row_fields` (SQLite) on the first test.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_08_08_120001_add_configuration_to_units.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-unit variation moves out of the hardcoded App\Support\UnitProfile registry and into
 * data (design §6.1), so a department is configuration rather than code.
 *
 * Additive and defaulted, per the project rule. The four paediatric units are BACKFILLED
 * here rather than left to a seeder: the owner's production database must arrive at the
 * right values from a migration alone, since seeders are not run against it. Under
 * RefreshDatabase the units table is empty, so this backfill is a no-op in the test suite —
 * ReferenceSeeder is what the tests exercise.
 */
return new class extends Migration
{
    /** The historical UnitProfile registry, verbatim — this is the data being moved. */
    private const PROFILES = [
        'PICU' => [1, '[]', 'Bed', true, 'Consultant covering', 'channel-bar-picu', 'Plan Of Care', 'New events'],
        'NICU' => [2, '["dob"]', 'Bed', true, 'Consultant covering', 'channel-bar-nicu', 'Plan Of Care', 'To be followed'],
        'SCBU' => [3, '["dob"]', 'Bed', true, 'Consultant covering', 'channel-bar-scbu', 'Plan Of Care', 'To be followed'],
        'WARD' => [4, '["age","ward_unit"]', 'Room', false, 'Consultant Oncall', 'channel-bar-ward', 'Management', 'To be followed'],
    ];

    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->unsignedSmallInteger('display_order')->default(0)->after('name');
            // Retirement is now explicit. The spec requires retired unit codes to 404;
            // previously that was expressed by absence from a PHP array.
            $table->boolean('active')->default(true)->after('display_order');
            $table->json('extra_row_fields')->nullable()->after('active');
            $table->string('bed_label')->default('Bed')->after('extra_row_fields');
            $table->boolean('consultant_pair')->default(true)->after('bed_label');
            $table->string('consultant_by_label')->default('Consultant covering')->after('consultant_pair');
            $table->string('bar_class')->nullable()->after('consultant_by_label');
            $table->string('print_plan_label')->default('Plan Of Care')->after('bar_class');
            $table->string('print_narrative_label')->default('To be followed')->after('print_plan_label');
        });

        foreach (self::PROFILES as $code => $p) {
            DB::table('units')->where('code', $code)->update([
                'display_order' => $p[0],
                'active' => true,
                'extra_row_fields' => $p[1],
                'bed_label' => $p[2],
                'consultant_pair' => $p[3],
                'consultant_by_label' => $p[4],
                'bar_class' => $p[5],
                'print_plan_label' => $p[6],
                'print_narrative_label' => $p[7],
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn([
                'display_order', 'active', 'extra_row_fields', 'bed_label',
                'consultant_pair', 'consultant_by_label', 'bar_class',
                'print_plan_label', 'print_narrative_label',
            ]);
        });
    }
};
```

- [ ] **Step 4: Update the seeder**

In `database/seeders/ReferenceSeeder.php`, replace the unit block (currently lines 41-51, the `$units = [...]` array through its `foreach`):

```php
        // The first-class clinical units. Codes are the routing identity
        // (/endorsement/{code}); names appear on screens and the printed sheet.
        //
        // Profile columns are seeded ONCE for a fresh install and then belong to the
        // department — a re-seed refreshes `name` only, so an admin's configuration is never
        // silently reverted. Existing databases were backfilled by the 2026_08_08 migration.
        $units = [
            'PICU' => ['Pediatric Intensive Care Unit', 1, [], 'Bed', true, 'Consultant covering', 'channel-bar-picu', 'Plan Of Care', 'New events'],
            'NICU' => ['Neonatal Intensive Care Unit', 2, ['dob'], 'Bed', true, 'Consultant covering', 'channel-bar-nicu', 'Plan Of Care', 'To be followed'],
            'SCBU' => ['Special Care Baby Unit', 3, ['dob'], 'Bed', true, 'Consultant covering', 'channel-bar-scbu', 'Plan Of Care', 'To be followed'],
            'WARD' => ['Pediatric Ward', 4, ['age', 'ward_unit'], 'Room', false, 'Consultant Oncall', 'channel-bar-ward', 'Management', 'To be followed'],
        ];

        foreach ($units as $code => $u) {
            $unit = Unit::firstOrNew(['code' => $code]);
            $unit->name = $u[0];

            if (! $unit->exists) {
                $unit->fill([
                    'display_order' => $u[1],
                    'active' => true,
                    'extra_row_fields' => $u[2],
                    'bed_label' => $u[3],
                    'consultant_pair' => $u[4],
                    'consultant_by_label' => $u[5],
                    'bar_class' => $u[6],
                    'print_plan_label' => $u[7],
                    'print_narrative_label' => $u[8],
                ]);
            }

            $unit->save();
        }
```

- [ ] **Step 5: Run the test — it will still fail on casts**

```powershell
php artisan test --filter UnitConfigurationTest | Select-Object -Last 15
```

Expected: FAIL — `extra_row_fields` comes back as the string `'[]'` rather than an array, and `fill()` silently drops the new keys, because the model has neither casts nor `$fillable` entries yet. That is Task 2.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_08_08_120001_add_configuration_to_units.php database/seeders/ReferenceSeeder.php tests/Feature/Units/UnitConfigurationTest.php
git commit -m "test: pin the four unit profiles as data, and add the columns to hold them"
```

- [ ] **Step 7: Record the production verification query**

The backfill cannot be covered by the suite. Append this to `docs/RUNBOOK-DEPLOY.md` under a new heading `## Verifying the 2026-08-08 unit-configuration migration`:

```markdown
After running `php artisan migrate` for `2026_08_08_120001_add_configuration_to_units`,
confirm the backfill landed. Expect exactly four rows, none with a NULL `bar_class`:

    SELECT code, display_order, active, bed_label, consultant_pair,
           consultant_by_label, bar_class, print_plan_label, print_narrative_label
    FROM units ORDER BY display_order;

PICU must read `Bed / 1 / Consultant covering / channel-bar-picu / Plan Of Care / New events`;
WARD must read `Room / 0 / Consultant Oncall / channel-bar-ward / Management / To be followed`.
A NULL `bar_class` means the row's `code` did not match the migration's constant — fix the
data, do not edit the migration after it has run.
```

- [ ] **Step 8: Commit the runbook**

```bash
git add docs/RUNBOOK-DEPLOY.md
git commit -m "docs: how to verify the unit-configuration backfill on production"
```

---

### Task 2: Teach the Unit model its new shape

**Files:**
- Modify: `app/Models/Unit.php`

- [ ] **Step 1: Rewrite the model**

Replace the whole of `app/Models/Unit.php`:

```php
<?php

namespace App\Models;

use App\Support\UnitProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A clinical unit. Since design §6.1 this row carries every per-unit difference — which
 * identity columns the sheet shows, how the consultant sign-off is shaped, the print labels
 * and the hue — so adding a department is configuration, not code.
 */
class Unit extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'display_order',
        'active',
        'extra_row_fields',
        'bed_label',
        'consultant_pair',
        'consultant_by_label',
        'bar_class',
        'print_plan_label',
        'print_narrative_label',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
            'active' => 'boolean',
            'consultant_pair' => 'boolean',
            'extra_row_fields' => 'array',
        ];
    }

    /**
     * @param  Builder<Unit>  $query
     * @return Builder<Unit>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * @param  Builder<Unit>  $query
     * @return Builder<Unit>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('display_order')->orderBy('id');
    }

    /**
     * Active unit codes in display order — the replacement for the old
     * `UnitProfile::codes()` static registry.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        return static::query()->active()->ordered()->pluck('code')->all();
    }

    /** The value object every surface reads (sheet columns, sign-off, print, hue). */
    public function profile(): UnitProfile
    {
        return UnitProfile::fromUnit($this);
    }
}
```

- [ ] **Step 2: Run the test to verify it passes**

```powershell
php artisan test --filter UnitConfigurationTest | Select-Object -Last 5
```

Expected: PASS, 5 tests.

- [ ] **Step 3: Commit**

```bash
git add app/Models/Unit.php
git commit -m "feat: units carry their own profile columns"
```

---

### Task 3: Build UnitProfile from a Unit row

**Files:**
- Modify: `app/Support/UnitProfile.php`
- Modify: `tests/Unit/UnitProfileTest.php` (replace contents entirely)

- [ ] **Step 1: Replace the test**

`tests/Unit/UnitProfileTest.php` currently extends `PHPUnit\Framework\TestCase` and asserts the static registry. Replace its entire contents. It now extends `Tests\TestCase` because it exercises Eloquent casts, but still needs no database — the models are never saved:

```php
<?php

namespace Tests\Unit;

use App\Models\Unit;
use App\Support\UnitProfile;
use Tests\TestCase;

/**
 * UnitProfile is still the single value object every surface reads — but since design §6.1
 * its VALUES come from the units row. These tests pin the mapping and the fallbacks a
 * freshly-created unit gets before an admin configures it.
 */
class UnitProfileTest extends TestCase
{
    public function test_it_maps_every_column_onto_the_value_object(): void
    {
        $unit = new Unit([
            'code' => 'WARD',
            'name' => 'Pediatric Ward',
            'extra_row_fields' => ['age', 'ward_unit'],
            'bed_label' => 'Room',
            'consultant_pair' => false,
            'consultant_by_label' => 'Consultant Oncall',
            'bar_class' => 'channel-bar-ward',
            'print_plan_label' => 'Management',
            'print_narrative_label' => 'To be followed',
        ]);

        $p = UnitProfile::fromUnit($unit);

        $this->assertSame('WARD', $p->code);
        $this->assertSame(['age', 'ward_unit'], $p->extraRowFields);
        $this->assertSame('Room', $p->bedLabel);
        $this->assertFalse($p->consultantPair);
        $this->assertSame('Consultant Oncall', $p->consultantByLabel);
        $this->assertSame('channel-bar-ward', $p->barClass);
        $this->assertSame('Management', $p->printPlanLabel);
        $this->assertSame('To be followed', $p->printNarrativeLabel);
    }

    public function test_a_bare_new_unit_gets_usable_defaults(): void
    {
        $p = UnitProfile::fromUnit(new Unit(['code' => 'CARD', 'name' => 'Cardiology']));

        $this->assertSame([], $p->extraRowFields);
        $this->assertSame('Bed', $p->bedLabel);
        $this->assertTrue($p->consultantPair);
        $this->assertSame('Consultant covering', $p->consultantByLabel);
        // A department that has not picked a hue still gets a stable, unique class.
        $this->assertSame('channel-bar-card', $p->barClass);
        $this->assertSame('Plan Of Care', $p->printPlanLabel);
        $this->assertSame('To be followed', $p->printNarrativeLabel);
    }

    public function test_the_code_is_normalized_to_upper_case(): void
    {
        $this->assertSame('WARD', UnitProfile::fromUnit(new Unit(['code' => 'ward']))->code);
    }

    /** The client receives the profile as a plain array via Inertia. */
    public function test_to_array_carries_the_client_contract(): void
    {
        $arr = UnitProfile::fromUnit(new Unit([
            'code' => 'WARD',
            'extra_row_fields' => ['age', 'ward_unit'],
            'bed_label' => 'Room',
            'consultant_pair' => false,
            'consultant_by_label' => 'Consultant Oncall',
            'bar_class' => 'channel-bar-ward',
            'print_plan_label' => 'Management',
            'print_narrative_label' => 'To be followed',
        ]))->toArray();

        $this->assertSame('WARD', $arr['code']);
        $this->assertSame(['age', 'ward_unit'], $arr['extra_row_fields']);
        $this->assertSame('Room', $arr['bed_label']);
        $this->assertFalse($arr['consultant_pair']);
        $this->assertSame('Consultant Oncall', $arr['consultant_by_label']);
        $this->assertSame('channel-bar-ward', $arr['bar_class']);
        $this->assertSame('Management', $arr['plan_label']);
        $this->assertSame('To be followed', $arr['narrative_label']);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```powershell
php artisan test --filter UnitProfileTest | Select-Object -Last 15
```

Expected: FAIL — `Call to undefined method App\Support\UnitProfile::fromUnit()`.

- [ ] **Step 3: Rewrite UnitProfile**

Replace `app/Support/UnitProfile.php` entirely:

```php
<?php

namespace App\Support;

use App\Models\Unit;

/**
 * The SINGLE value object describing per-unit variation. Every surface — sheet columns,
 * sign-off fields, print schema, nav/chooser hues — reads from here, so the units can never
 * drift apart the way the four copy-pasted legacy code families did.
 *
 * Since design §6.1 the SHAPE lives here and the VALUES live on the `units` row, so adding a
 * department is configuration rather than a code change. The fallbacks below are what a
 * freshly-created unit gets before an admin configures it.
 */
final class UnitProfile
{
    /**
     * @param list<string> $extraRowFields  extra writable identity columns beyond bed/mrn/name
     */
    private function __construct(
        public readonly string $code,
        public readonly array $extraRowFields,
        public readonly string $bedLabel,
        public readonly bool $consultantPair,
        public readonly string $consultantByLabel,
        public readonly string $barClass,
        public readonly string $printPlanLabel,
        public readonly string $printNarrativeLabel,
    ) {
    }

    public static function fromUnit(Unit $unit): self
    {
        $code = strtoupper((string) $unit->code);

        return new self(
            code: $code,
            extraRowFields: array_values($unit->extra_row_fields ?? []),
            bedLabel: $unit->bed_label ?? 'Bed',
            consultantPair: $unit->consultant_pair ?? true,
            consultantByLabel: $unit->consultant_by_label ?? 'Consultant covering',
            barClass: $unit->bar_class ?? 'channel-bar-'.strtolower($code),
            printPlanLabel: $unit->print_plan_label ?? 'Plan Of Care',
            printNarrativeLabel: $unit->print_narrative_label ?? 'To be followed',
        );
    }

    /**
     * The client contract, shared via Inertia as `unit.profile`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'extra_row_fields' => $this->extraRowFields,
            'bed_label' => $this->bedLabel,
            'consultant_pair' => $this->consultantPair,
            'consultant_by_label' => $this->consultantByLabel,
            'bar_class' => $this->barClass,
            'plan_label' => $this->printPlanLabel,
            'narrative_label' => $this->printNarrativeLabel,
        ];
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

```powershell
php artisan test --filter UnitProfileTest | Select-Object -Last 5
```

Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Support/UnitProfile.php tests/Unit/UnitProfileTest.php
git commit -m "refactor: build UnitProfile from the units row instead of a static registry"
```

---

### Task 4: Point EndorsementController at the database

**Files:**
- Modify: `app/Http/Controllers/EndorsementController.php` (lines 28, 46, 48, 60, 165, 167, 175, 195, 338, 644, 665, 723, 736, 1116)

The suite is red between Steps 2 and 4; Step 4 is the gate.

- [ ] **Step 1: Run the full suite to record the starting point**

```powershell
php artisan test | Select-Object -Last 5
```

Expected: PASS. **Write down the test count** — it must not drop by the end of this task.

- [ ] **Step 2: Replace every call site**

Nine `UnitProfile::` calls plus two sort lines. Apply each exactly:

| Line | Replace | With |
|---|---|---|
| 46 | `Unit::whereIn('code', UnitProfile::codes())` | `Unit::query()->active()->ordered()` |
| 48 | `->sortBy(fn (Unit $u): int => (int) array_search($u->code, UnitProfile::codes(), true))` | *delete the line* — `ordered()` already sorts |
| 60 | `'bar_class' => UnitProfile::for($u->code)->barClass,` | `'bar_class' => $u->profile()->barClass,` |
| 165 | `Unit::whereIn('code', UnitProfile::codes())` | `Unit::query()->active()->ordered()` |
| 167 | `->sortBy(...)` (same shape as line 48) | *delete the line* |
| 175 | `'bar_class' => UnitProfile::for($u->code)->barClass,` | `'bar_class' => $u->profile()->barClass,` |
| 195 | `if ($unit === null \|\| ! in_array($unit->code, UnitProfile::codes(), true)) {` | `if ($unit === null \|\| ! $unit->active) {` |
| 338 | `if (! UnitProfile::for($u->code)->consultantPair) {` | `if (! $u->profile()->consultantPair) {` |
| 644 | `$this->validateRow($request, UnitProfile::for($u->code))` | `$this->validateRow($request, $u->profile())` |
| 665 | `$this->validateRow($request, UnitProfile::for((string) $handover->unit?->code))` | `$this->validateRow($request, $handover->unit->profile())` |
| 723 | `if (! in_array(strtoupper((string) $handover->unit?->code), UnitProfile::codes(), true)) {` | `if ($handover->unit === null \|\| ! $handover->unit->active) {` |
| 736 | `if (! in_array($code, UnitProfile::codes(), true)) {` | `if (! in_array($code, Unit::codes(), true)) {` |
| 1116 | `'profile' => UnitProfile::for($unit->code)->toArray(),` | `'profile' => $unit->profile()->toArray(),` |

**Keep** the `use App\Support\UnitProfile;` import on line 12 — the `validateRow(Request $request, UnitProfile $profile)` type-hint still needs it.

- [ ] **Step 3: Update the stale comment on line 28**

Replace:

```php
 * `App\Support\UnitProfile` rather than drifting per code path.
```

with:

```php
 * the unit's own `profile()` rather than drifting per code path.
```

- [ ] **Step 4: Verify only the type-hint remains, then run the suite**

```powershell
Select-String -Path app\Http\Controllers\EndorsementController.php -Pattern "UnitProfile"
```

Expected: exactly two hits — the `use` statement (line 12) and the `validateRow` signature.

```powershell
php artisan test | Select-Object -Last 5
```

Expected: PASS, same test count as Step 1.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/EndorsementController.php
git commit -m "refactor: resolve unit profiles through the model, not the static registry"
```

---

### Task 5: Update the two remaining consumers

**Files:**
- Modify: `app/Console/Commands/SendHandoverReminders.php:8,39`
- Modify: `app/Http/Controllers/ProfileController.php:46`

- [ ] **Step 1: Update SendHandoverReminders**

Line 39:

```php
foreach (UnitProfile::codes() as $code) {
```

becomes:

```php
foreach (Unit::codes() as $code) {
```

Delete `use App\Support\UnitProfile;` (line 8) and ensure `use App\Models\Unit;` is present in the import block.

- [ ] **Step 2: Update ProfileController**

Line 46:

```php
'units' => \App\Models\Unit::whereIn('code', \App\Support\UnitProfile::codes())
```

becomes:

```php
'units' => \App\Models\Unit::query()->active()->ordered()
```

- [ ] **Step 3: Verify no static-registry callers remain anywhere**

```powershell
Select-String -Path app\ -Pattern "UnitProfile::(codes|for)\(" -Recurse
```

Expected: **no matches.**

- [ ] **Step 4: Run the full suite**

```powershell
php artisan test | Select-Object -Last 5
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/SendHandoverReminders.php app/Http/Controllers/ProfileController.php
git commit -m "refactor: last two unit-code callers read the database"
```

---

### Task 6: Retired units 404

**Files:**
- Create: `tests/Feature/Units/RetiredUnitTest.php`

The spec requires unknown or retired unit codes to 404. Task 1 gave retirement an explicit flag; this proves it end to end.

- [ ] **Step 1: Write the test**

Create `tests/Feature/Units/RetiredUnitTest.php`. The `setUp` and admin construction mirror `tests/Feature/Endorsement/EndorsementTest.php:26-44` exactly — both seeders are required, because capabilities come from `AccessControlSeeder`:

```php
<?php

namespace Tests\Feature\Units;

use App\Models\Unit;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec §3: unknown or retired unit codes 404. Retirement used to mean "absent from a PHP
 * array"; since design §6.1 it means `active = false`, and it must behave identically.
 */
class RetiredUnitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['position' => 0]);
    }

    public function test_a_deactivated_unit_404s_and_leaves_the_code_list(): void
    {
        Unit::where('code', 'SCBU')->update(['active' => false]);

        $this->actingAs($this->admin())
            ->get('/endorsement/SCBU/'.now()->toDateString())
            ->assertNotFound();

        $this->assertNotContains('SCBU', Unit::codes());
    }

    public function test_an_active_unit_still_resolves(): void
    {
        $this->actingAs($this->admin())
            ->get('/endorsement/SCBU/'.now()->toDateString())
            ->assertOk();
    }

    public function test_an_unknown_code_404s(): void
    {
        $this->actingAs($this->admin())
            ->get('/endorsement/ICU/'.now()->toDateString())
            ->assertNotFound();
    }
}
```

- [ ] **Step 2: Run it**

```powershell
php artisan test --filter RetiredUnitTest | Select-Object -Last 15
```

Expected: PASS, 3 tests. If the deactivated-unit case returns 200, the guard at line 195 still matches on code rather than `active` — fix it there, not in the test.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Units/RetiredUnitTest.php
git commit -m "test: retired units 404 through the active flag"
```

---

### Task 7: Correct the documents this invalidates

**Files:**
- Modify: `CLAUDE.md`
- Modify: `docs/spec/03-unit-model.md`

Both currently state things that are now false, and both are read as law by every future session.

- [ ] **Step 1: Update CLAUDE.md's opening scope paragraph**

Replace:

```
A standalone shift-handover (endorsement) system for four paediatric units — PICU, NICU,
SCBU, WARD. Endorsement ONLY: no registry, no scoring, no KPI dashboards (beyond the
missed-days counter), no nursing sheets.
```

with:

```
A departmental clinical platform. Two modules: **Endorsement** (shift handover, holds PHI)
and **Rota** (duty scheduling, holds none) — see
`docs/superpowers/specs/2026-08-08-munawib-endorsement-integration-design.md`.

Endorsement covers handover ONLY: no registry, no scoring, no KPI dashboards (beyond the
missed-days counter), no nursing sheets. Units are CONFIGURATION, not code — PICU, NICU,
SCBU and WARD are seed data for the QCH institution.
```

- [ ] **Step 2: Update CLAUDE.md's unit-variation rule**

Replace:

```
- Unit variation lives in ONE place: `App\Support\UnitProfile`.
```

with:

```
- Unit variation lives in ONE place: the `units` row. `App\Support\UnitProfile` is the value
  object that shape travels in (`$unit->profile()`); it holds no per-unit values. Never
  reintroduce a hardcoded unit list — `Unit::codes()` is the only source.
```

- [ ] **Step 3: Update docs/spec/03-unit-model.md**

Replace its first two paragraphs — the `UNIT_CODES` sentence and the "single UnitProfile value object" sentence — with:

```markdown
`units` is the source of unit identity and of every per-unit difference. `Unit::codes()`
returns the active codes in `display_order`; unknown codes and units with `active = false`
404 (lowercase URL codes keep resolving, as in the reference).

Per-unit variation is **configuration on the `units` row** — `extra_row_fields`, `bed_label`,
`consultant_pair`, `consultant_by_label`, `bar_class`, `print_plan_label`,
`print_narrative_label`. `App\Support\UnitProfile` is the value object carrying that shape to
PHP and, via Inertia, to Vue (`$unit->profile()`); it holds no values of its own.

The table below is the SEEDED configuration for the QCH paediatric institution, not a
description of the code.
```

Leave the existing four-column table beneath it — it is now accurate as seed data. Add one line under the table:

```markdown
Adding a department means inserting a `units` row and configuring these columns. No code changes.
```

- [ ] **Step 4: Verify the suite and the build**

```powershell
php artisan test | Select-Object -Last 5
```

```powershell
npm run build 2>&1 | Select-Object -Last 5
```

Expected: tests PASS; build succeeds.

- [ ] **Step 5: Commit**

```bash
git add CLAUDE.md docs/spec/03-unit-model.md
git commit -m "docs: units are configuration; correct the rules that said otherwise"
```

---

## Definition of done

- `Select-String -Path app\ -Pattern "UnitProfile::(codes|for)\(" -Recurse` returns nothing.
- `php artisan test` passes with no fewer tests than before Task 4.
- `npm run build` succeeds.
- The four paediatric units render, validate, sign off and print exactly as before — the parity assertions in `UnitConfigurationTest` are the evidence.
- A new unit can be created by inserting a row and gets usable defaults with no code change.
- Deactivating a unit 404s its routes and drops it from `Unit::codes()`.
- `CLAUDE.md` and `docs/spec/03-unit-model.md` no longer describe a hardcoded four-unit system.
- `docs/RUNBOOK-DEPLOY.md` carries the production backfill verification query.

## Next plan

**P0b — Bounded custom fields (Ceiling 2):** `unit_field_definitions`, an encrypted
`handovers.extra_fields` JSON column, dynamic validation built from definitions, a generic
renderer in `Sheet.vue` and `Print.vue`, and legacy-import mapping. Depends on this plan and
nothing else.
