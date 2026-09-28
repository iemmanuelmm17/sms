<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One row per allowed IP or CIDR range for the superadmin portal. */
class SuperAdminAllowedIp extends Model
{
    /** Migration named it without the middle underscore; point at the real table. */
    protected $table = 'superadmin_allowed_ips';

    protected $fillable = ['cidr', 'label'];
}
