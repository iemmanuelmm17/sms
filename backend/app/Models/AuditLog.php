<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only audit trail (phase-3 view reads this table). */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['domain', 'actor_type', 'actor_id', 'actor_name',
        'action', 'detail', 'ip_address', 'created_at'];

    protected $casts = ['detail' => 'array', 'created_at' => 'datetime'];

    public static function record(?string $domain, string $actorType, $actorId,
        ?string $actorName, string $action, array $detail = [], ?string $ip = null): void
    {
        try {
            self::create([
                'domain' => $domain, 'actor_type' => $actorType,
                'actor_id' => is_numeric($actorId) ? (int) $actorId : null,
                'actor_name' => $actorName, 'action' => $action,
                'detail' => $detail ?: null, 'ip_address' => $ip,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Auditing must never break the request it observes.
        }
    }
}
