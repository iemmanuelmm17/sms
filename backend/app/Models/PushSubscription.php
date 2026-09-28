<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Web Push subscription for one agent or tenant admin (background alerts). */
class PushSubscription extends Model
{
    protected $fillable = [
        'agent_id', 'tenant_admin_id', 'domain', 'user',
        'endpoint', 'endpoint_hash', 'p256dh', 'auth',
    ];

    public function toWebPushArray(): array
    {
        return [
            'endpoint' => $this->endpoint,
            'keys' => ['p256dh' => $this->p256dh, 'auth' => $this->auth],
        ];
    }
}
