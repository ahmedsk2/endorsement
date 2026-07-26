<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\SignatureStore;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serve a stored staff signature image.
 *
 * Signatures are NOT public files: they are a forgeable credential and personal data, so
 * every read goes through here — authenticated, and (for another person's signature)
 * gated on `endorsement.view`, because the only legitimate reason to see someone else's
 * signature is a handover sheet that carries it. Responses are marked no-store.
 */
class SignatureController extends Controller
{
    /** Your own signature — the profile page preview. */
    public function mine(Request $request): Response
    {
        $png = SignatureStore::read($request->user()?->signature_path);

        if ($png === null) {
            abort(404);
        }

        return $this->png($png);
    }

    public function show(Request $request, User $user): Response
    {
        $viewer = $request->user();

        if ($viewer === null) {
            abort(401);
        }

        // YOUR OWN SIGNATURE ONLY.
        //
        // This used to serve any user's current signature to any holder of
        // endorsement.view — which is every clinical role — so ids 1..N were enumerable and
        // a handwritten signature is, as this class's own docblock says, a forgeable
        // credential. Its value is mostly OUTSIDE this system: on consent forms, leave
        // requests, prescriptions.
        //
        // Nothing legitimate needs it. Rendering a signed sheet uses the content-addressed
        // file/{hash} route below, which serves the exact image frozen at sign-off rather
        // than whatever the person's signature happens to be today.
        if ($viewer->getKey() !== $user->getKey()) {
            AuditLog::record('signature_access_denied', 'target='.$user->getKey(), $viewer->getKey(), $request->ip());

            abort(403);
        }

        $png = SignatureStore::read($user->signature_path);

        if ($png === null) {
            abort(404);
        }

        return $this->png($png);
    }

    /**
     * Serve a signature by its CONTENT HASH — how a signed handover renders the exact
     * signature it was signed with, rather than whatever that person's signature happens
     * to be today. Files are content-addressed and immutable, so the hash IS the version.
     */
    public function file(Request $request, string $hash): Response
    {
        $viewer = $request->user();

        if ($viewer === null) {
            abort(401);
        }

        if (! $viewer->can('endorsement.view')) {
            abort(403);
        }

        // Hex only — the path is built from this, so nothing else may enter it.
        if (! preg_match('/^[a-f0-9]{64}$/', $hash)) {
            abort(404);
        }

        $png = SignatureStore::read('signatures/'.$hash.'.png');

        if ($png === null) {
            abort(404);
        }

        return $this->png($png);
    }

    private function png(string $bytes): Response
    {
        return response($bytes, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline; filename="signature.png"',
            'Cache-Control' => 'no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
