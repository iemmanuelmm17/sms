<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Agent forgot-password challenge lifecycle (audit trail included). */
class PasswordResetRequest extends Model
{
    protected $fillable = ['agent_id', 'domain', 'username', 'token_hash',
        'verified_at', 'completed_at', 'attempts', 'ip_address'];

    protected $casts = ['verified_at' => 'datetime', 'completed_at' => 'datetime'];

    protected $hidden = ['token_hash'];
}
