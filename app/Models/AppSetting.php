<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One admin-editable runtime setting. Dumb storage — the known-key list, validation,
 * secret encryption and config mapping all live in App\Support\AppSettings.
 */
class AppSetting extends Model
{
    protected $fillable = ['key', 'value'];
}
