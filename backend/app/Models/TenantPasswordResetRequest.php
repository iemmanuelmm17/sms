<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Tenant-admin forgot-password challenge lifecycle (audit trail included). */
class TenantPasswordResetRequest extends Model
{
    protected $fillable = ['tenant_admin_id', 'tenant_id', 'domain', 'username', 'token_hash',
        'verified_at', 'completed_at', 'attempts', 'ip_address'];

    protected $casts = ['verified_at' => 'datetime', 'completed_at' => 'datetime'];

    protected $hidden = ['token_hash'];
}
