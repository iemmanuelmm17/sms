<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Superadmin: global login, manages tenants. Table allows more later. */
class SuperAdmin extends Model
{
    protected $fillable = ['username', 'first_name', 'last_name', 'password_hash',
        'status', 'session_version', 'must_change_password', 'last_seen_at'];

    protected $hidden = ['password_hash'];

    protected $casts = ['last_seen_at' => 'datetime', 'must_change_password' => 'boolean'];

    public function isActive(): bool
    {
        return ($this->status ?: 'active') === 'active';
    }

    public function displayName(): string
    {
        $n = trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));
        return $n !== '' ? $n : (string) $this->username;
    }
}
