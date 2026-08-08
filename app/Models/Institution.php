<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Institution extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'code',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * The single institution this deployment belongs to (D11: one database, one customer).
     *
     * Returns null when there is none — a deployment that has not been seeded — or when there
     * is more than one, because in that case there is no right answer and guessing would stamp
     * clinical provenance with a coin flip. Callers treat null as "leave it NULL".
     */
    public static function current(): ?self
    {
        $rows = static::query()->where('active', true)->limit(2)->get();

        return $rows->count() === 1 ? $rows->first() : null;
    }
}
