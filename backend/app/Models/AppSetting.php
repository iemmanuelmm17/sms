<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Global key/value store (Dynalink client id/secret, feature flags). */
class AppSetting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    protected $hidden = ['value'];

    protected $casts = ['value' => 'encrypted'];
}
