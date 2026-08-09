<?php

namespace Tests\Feature\Build;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * PE-02, enforced rather than intended.
 *
 * `Person::$hidden = ['phone', 'notes']` bites on toArray()/toJson() ONLY. Every admin screen in
 * this codebase builds its Inertia props with an explicit present() map (UnitController,
 * LevelController, UserManagementController), which reads attributes directly and never consults
 * $hidden. A People screen written in the house style with `'phone' => $person->phone` would
 * publish a clinician's mobile number to every viewer with $hidden fully in place.
 *
 * So `App\Support\PersonPresenter` is the ONE place a Person becomes props, it takes the viewing
 * user, and this test is what stops a second one appearing. Same species as
 * CalendarIsTheOnlyConverterTest and InstitutionProvenanceTest — conventions decay, tests do not.
 *
 * Scanned: app/ + database/ + routes/. NOT database/factories/ — a factory populating a fixture
 * phone number is not a disclosure surface.
 */
class ContactFieldsAreProjectedOnceTest extends TestCase
{
    /** Every file allowed to touch a person's contact fields, with why. */
    private const ALLOW_LIST = [
        // The one projection. This is the control.
        'app/Support/PersonPresenter.php',
        // Declares the columns ($fillable) and hides them from accidental serialisation ($hidden).
        'app/Models/Person.php',
        // Declares the COLUMNS ($table->string('phone', 32), $table->text('notes')) — a schema
        // definition, not a read. Matched only because the needle list is a plain substring scan
        // and cannot tell "the column exists" from "the value was read". Verified empirically
        // (finding 2's own text already names this file as one of the "zero reads" matches).
        'database/migrations/2026_08_10_120001_create_people_and_link_users.php',
        // The write-side validation names the fields it accepts; it renders nothing.
        'app/Http/Requests/Admin/PersonRequest.php',
        // P1c Task 9 (LV-02 export): `array_key_exists('phone', $projected)` and `$p['phone']`
        // in `PersonController::exportTable()` read the KEY off an array `PersonPresenter::one()`
        // already built — never `->phone` off a Person model — to decide whether the CSV needs a
        // Phone column at all. Same content-blind, presence-only pattern `People.vue`'s own
        // `'phone' in person` check already uses for the identical reason (that file is outside
        // this guard's scanned directories); matched here only because the needle is a plain
        // substring scan that cannot distinguish "reading a key that exists" from "reading the
        // model column directly".
        'app/Http/Controllers/Admin/PersonController.php',
    ];

    private const NEEDLES = ['->phone', '->notes', "'phone'", "'notes'"];

    public function test_only_the_presenter_reads_a_persons_contact_fields(): void
    {
        $offenders = [];

        foreach ([app_path(), base_path('database'), base_path('routes')] as $dir) {
            foreach (File::allFiles($dir) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $relative = str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()));

                if (in_array($relative, self::ALLOW_LIST, true) || str_starts_with($relative, 'database/factories/')) {
                    continue;
                }

                $contents = (string) File::get($file->getPathname());

                foreach (self::NEEDLES as $needle) {
                    if (str_contains($contents, $needle)) {
                        $offenders[] = $relative.' contains '.$needle;
                    }
                }
            }
        }

        $this->assertSame([], $offenders,
            "Staff contact fields must be projected only by App\\Support\\PersonPresenter (PE-02).\n"
            .implode("\n", $offenders));
    }

    /** A stale allow-list is a silently disabled guard. */
    public function test_every_allow_listed_file_still_exists(): void
    {
        foreach (self::ALLOW_LIST as $relative) {
            $this->assertFileExists(base_path($relative), "Allow-listed file {$relative} is gone — prune the list.");
        }
    }
}
