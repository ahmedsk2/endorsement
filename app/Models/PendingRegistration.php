<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PendingRegistration extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'institution_id',
        'full_name',
        'member_name',
        'member_email',
        'password',
        'position',
        'requested_at',
        'email_verified_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'position' => 'integer',
            'requested_at' => 'datetime',
            'email_verified_at' => 'datetime',
        ];
    }
}
