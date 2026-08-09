<?php

namespace App\Support;

use App\Models\Institution;

/**
 * PE-02's department setting. The one reader and the one writer of
 * `institutions.contact_visibility`.
 *
 * NOT a calendar column: `Calendar::settings()` memoises the six calendar values as an array,
 * not the Institution model, so a write here leaves nothing stale for `Calendar::flush()` to
 * clear. That is the stated reason this file is allow-listed in `CalendarWritersFlushTest` —
 * the guard's needle list includes `Institution::current()`, which any reader of any column on
 * that row necessarily calls.
 *
 * Falls back to the STRICTER value when no institution row exists: `RefreshDatabase` leaves
 * `institutions` empty until something seeds it, and a missing row must never mean "show
 * everyone everything".
 */
final class ContactVisibility
{
    public static function current(): string
    {
        $value = Institution::current()?->contact_visibility;

        return array_key_exists((string) $value, Institution::CONTACT_VISIBILITIES)
            ? (string) $value
            : Institution::CONTACT_ADMINS;
    }

    /**
     * BOTH contact fields, not just the phone number — `membersMaySeePhone()` until pre-merge
     * finding 2 renamed it. `PersonPresenter` releases `email` and `phone` from ONE branch, and it
     * has done since P1d Task 7 made the gate real; a predicate named for half of what it decides
     * is how ruling 21 came to describe half of it too.
     */
    public static function membersMaySeeContact(): bool
    {
        return self::current() === Institution::CONTACT_MEMBERS;
    }

    public static function set(string $value): void
    {
        $institution = Institution::current();

        if ($institution === null) {
            return;
        }

        $institution->contact_visibility = $value;
        $institution->save();
    }
}
