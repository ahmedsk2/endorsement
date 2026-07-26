<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class AuditLog extends Model
{
    /**
     * @var string
     */
    protected $table = 'audit_log';

    /**
     * Append-only trail: only `created_at` is maintained.
     */
    const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'action',
        'detail',
        'ip',
        'prev_hash',
        'hash',
        'hash_version',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Append one row to the tamper-evident trail, chaining `hash` from the previous row's
     * `hash` (`hash = sha256(prev_hash + canonical(row))`). `detail` must never carry PHI —
     * pass ids/counts only.
     */
    public static function record(string $action, ?string $detail = null, ?int $userId = null, ?string $ip = null): self
    {
        // Serialize the read-prev-hash + append so two concurrent audited actions cannot read
        // the same prev_hash and fork the chain. lockForUpdate holds the row on InnoDB;
        // SQLite serializes writers anyway.
        return DB::transaction(function () use ($action, $detail, $userId, $ip) {
            $prevHash = static::query()->orderByDesc('id')->lockForUpdate()->value('hash');
            $createdAt = now();

            // Canonicalised by AuditChain so the writer and `audit:verify` can never drift
            // apart again — that drift is what made the trail report itself as tampered.
            $canonical = \App\Support\AuditChain::canonical($userId, $action, $detail, $ip, $createdAt);

            return static::create([
                'user_id' => $userId,
                'action' => $action,
                'detail' => $detail,
                'ip' => $ip,
                'prev_hash' => $prevHash,
                // KEYED, with the key held outside the database — see App\Support\AuditChain.
                'hash' => \App\Support\AuditChain::hash($prevHash, $canonical),
                'hash_version' => \App\Support\AuditChain::VERSION,
                'created_at' => $createdAt,
            ]);
        });
    }
}
