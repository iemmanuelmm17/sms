<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Source IPs allowed to POST Dynalink webhooks (superadmin-managed). */
class WebhookAllowedIp extends Model
{
    protected $table = 'webhook_allowed_ips';

    protected $fillable = ['cidr', 'label'];
}
