<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One login attempt (agent or admin). Powers the 3-fail / 5-min lockout. */
class LoginAttempt extends Model
{
    public $timestamps = false;

    protected $fillable = ['username', 'ip_address', 'attempted_at', 'success'];

    protected $casts = ['attempted_at' => 'datetime', 'success' => 'boolean'];
}
