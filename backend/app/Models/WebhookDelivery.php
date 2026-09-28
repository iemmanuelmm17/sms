<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One outbound webhook delivery attempt (visible in Integration → recent deliveries). */
class WebhookDelivery extends Model
{
    protected $fillable = [
        'tenant_webhook_id', 'event', 'payload', 'status_code', 'error', 'attempt',
    ];

    protected $casts = ['payload' => 'array'];

    public function webhook()
    {
        return $this->belongsTo(TenantWebhook::class, 'tenant_webhook_id');
    }
}
