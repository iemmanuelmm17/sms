<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Raw inbound Dynalink webhook events (diagnostics viewer in Auto-reply). */
class WebhookEvent extends Model
{
    protected $fillable = ['domain', 'user', 'correlation_id', 'event'];

    protected $casts = ['event' => 'array'];
}
