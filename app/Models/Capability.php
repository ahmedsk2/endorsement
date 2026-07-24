<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Capability extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'label',
        'description',
    ];

    /**
     * @return HasMany<RoleCapability, $this>
     */
    public function roleCapabilities(): HasMany
    {
        return $this->hasMany(RoleCapability::class);
    }

    /**
     * @return HasMany<UserCapability, $this>
     */
    public function userCapabilities(): HasMany
    {
        return $this->hasMany(UserCapability::class);
    }
}
