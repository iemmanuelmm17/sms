<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Dialog state for one customer on one integration.
 * expires_at slides 60 minutes on every handled message; a message
 * arriving after expiry starts a fresh session (welcome greeting).
 * data holds pending multi-step context (e.g. the account awaiting
 * its billing-code check).
 */
class IntegrationSession extends Model
{
    protected $fillable = ['integration_id', 'phone', 'state', 'data', 'last_activity_at', 'expires_at'];

    protected $casts = [
        'data' => 'array',
        'last_activity_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function integration()
    {
        return $this->belongsTo(Integration::class);
    }
}
