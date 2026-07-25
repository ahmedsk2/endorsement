<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Handwritten staff signatures — stored PRIVATELY and IMMUTABLY.
 *
 *  - PRIVATE: files live on the `local` disk (never public/), and are only ever served
 *    through an authenticated, capability-gated controller. A signature is personal data
 *    and a forgeable credential; a guessable public URL is not acceptable for either.
 *  - IMMUTABLE: the filename is the content hash, so re-signing writes a NEW file and
 *    never overwrites an old one. A handover signed last week therefore keeps the exact
 *    signature that was used, even after the person redraws theirs (medico-legal parity
 *    with the frozen name snapshots).
 *  - RE-ENCODED: an upload is decoded and re-emitted as a PNG through GD, which strips
 *    EXIF, embedded scripts and anything polyglot — a file that survives that round trip
 *    is an image and nothing else.
 */
final class SignatureStore
{
    private const DIR = 'signatures';

    /** Generous for a signature, small enough that nobody parks a payload here. */
    private const MAX_BYTES = 2_000_000;

    private const MAX_DIMENSION = 2000;

    /** Store an uploaded image file. Returns the storage path. */
    public static function putUpload(UploadedFile $file): string
    {
        $raw = (string) file_get_contents($file->getRealPath());

        return self::putRaw($raw);
    }

    /** Store a canvas data URL ("data:image/png;base64,..."). Returns the storage path. */
    public static function putDataUrl(string $dataUrl): string
    {
        if (! preg_match('#^data:image/(png|jpeg);base64,#i', $dataUrl, $m)) {
            throw ValidationException::withMessages([
                'signature' => 'The drawn signature was not readable. Try again.',
            ]);
        }

        $encoded = substr($dataUrl, strpos($dataUrl, ',') + 1);

        if (strlen($encoded) > self::MAX_BYTES * 2) {
            throw ValidationException::withMessages(['signature' => 'That signature image is too large.']);
        }

        $raw = base64_decode($encoded, true);

        if ($raw === false) {
            throw ValidationException::withMessages([
                'signature' => 'The drawn signature was not readable. Try again.',
            ]);
        }

        return self::putRaw($raw);
    }

    /**
     * Validate, re-encode and store. The re-encode is the security step: anything that is
     * not a real raster image dies here rather than being served back to a browser later.
     */
    private static function putRaw(string $raw): string
    {
        if (strlen($raw) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['signature' => 'That signature image is too large.']);
        }

        $info = @getimagesizefromstring($raw);

        if ($info === false || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            throw ValidationException::withMessages([
                'signature' => 'Upload a PNG or JPEG image of your signature.',
            ]);
        }

        if ($info[0] > self::MAX_DIMENSION || $info[1] > self::MAX_DIMENSION) {
            throw ValidationException::withMessages([
                'signature' => 'That image is too large — please keep it under 2000 pixels.',
            ]);
        }

        $image = @imagecreatefromstring($raw);

        if ($image === false) {
            throw ValidationException::withMessages([
                'signature' => 'That file is not a readable image.',
            ]);
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        // Content-addressed: identical drawings collapse to one file, and no write ever
        // clobbers a signature an existing sign-off still points at.
        $path = self::DIR.'/'.hash('sha256', $png).'.png';

        if (! Storage::disk('local')->exists($path)) {
            Storage::disk('local')->put($path, $png);
        }

        return $path;
    }

    /** The PNG bytes for a stored path, or null when the file is gone. */
    public static function read(?string $path): ?string
    {
        if ($path === null || $path === '' || ! str_starts_with($path, self::DIR.'/')) {
            return null;
        }

        return Storage::disk('local')->exists($path)
            ? (string) Storage::disk('local')->get($path)
            : null;
    }
}
