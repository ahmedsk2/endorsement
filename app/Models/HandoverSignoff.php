<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * H / GAP-5 — the PER-DAY handover attestation (legacy `endorsement` day-header row, written by
 * `validate-endorsement.php:8-16`). One row per (unit, handover_date).
 *
 * The four staff fields each carry BOTH a user FK and a frozen `*_name` snapshot: the snapshot is
 * what a signed sheet says forever, the FK is only a pointer back to the account. See the migration
 * docblock for the full rationale and the correction-path contract.
 */
class HandoverSignoff extends Model
{
    use SoftDeletes;

    /**
     * The legacy shift-change labels offered by `picu-endorsement-patients.php:431`. These are the
     * unit's two handover times as the legacy sheet spells them — an operational fact carried over
     * verbatim, NOT a clinical threshold. They remain the QUICK DEFAULT so routine use is unchanged;
     * an off-schedule handover is entered as a real clock time instead (see normalizeTimeLabel()).
     *
     * @var list<string>
     */
    public const TIME_OPTIONS = ['7:30 Am', '13:30'];

    /**
     * Parse a handover-time label into MINUTES PAST MIDNIGHT (0..1439), or null when the string
     * names no clock time.
     *
     * This is the single parser for the whole system — the write path, the backfill migration and
     * any future reporting all go through it, so a stored display string and its stored minutes can
     * never disagree. Accepts both legacy notations ('7:30 Am', '13:30') and a plain 24-hour entry
     * ('02:40'). A meridiem on an hour above 12 is contradictory and rejected rather than guessed at.
     *
     * Notation rules only — nothing here is clinical.
     */
    public static function parseTimeToMinutes(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        if (preg_match('/^\s*(\d{1,2})\s*:\s*([0-5]\d)\s*(am|pm)?\s*$/i', $value, $m) !== 1) {
            return null;
        }

        $hour = (int) $m[1];
        $minute = (int) $m[2];
        $meridiem = isset($m[3]) && $m[3] !== '' ? strtolower($m[3]) : null;

        if ($meridiem !== null) {
            if ($hour < 1 || $hour > 12) {
                return null;   // "13:30 PM" is not a time anyone meant; do not guess
            }

            $hour %= 12;                       // 12 AM => 0, 12 PM => 12 (after the += below)
            $hour += $meridiem === 'pm' ? 12 : 0;
        } elseif ($hour > 23) {
            return null;
        }

        return $hour * 60 + $minute;
    }

    /**
     * The DISPLAY string to store for a submitted time.
     *
     * A legacy quick-pick is kept CHARACTER FOR CHARACTER ('7:30 Am' stays '7:30 Am'), because that
     * is what the printed sheet has always said and what every imported row holds. Anything else is
     * normalized to unambiguous 24-hour 'HH:MM'. Returns null when the input names no clock time —
     * callers decide whether that is "cleared" or "rejected".
     */
    public static function normalizeTimeLabel(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        foreach (self::TIME_OPTIONS as $option) {
            if (strcasecmp(trim($value), $option) === 0) {
                return $option;
            }
        }

        $minutes = self::parseTimeToMinutes($value);

        if ($minutes === null) {
            return null;
        }

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'institution_id',
        'unit_id',
        'handover_date',
        'endorsed_by_user_id',
        'endorsed_by_name',
        'endorsed_to_user_id',
        'endorsed_to_name',
        'consultant_by_user_id',
        'consultant_by_name',
        'consultant_to_user_id',
        'consultant_to_name',
        'endorsement_time',
        'endorsement_time_minutes',
        'signed_off_at',
        'signed_off_by_user_id',
        'reopened_at',
        'reopened_by_user_id',
        'reopen_reason',
        'legacy_source_table',
        'legacy_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'handover_date' => 'date',
            'endorsement_time_minutes' => 'integer',
            'signed_off_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    /** A day is signed when it carries a sign-off stamp that has not since been reopened. */
    public function isSignedOff(): bool
    {
        return $this->signed_off_at !== null;
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function signedOffBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_off_by_user_id');
    }
}
