<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduledMessage extends Model
{
    protected $fillable = [
        'domain', 'user', 'name', 'message', 'from_number', 'type',
        'media_data', 'media_mime', 'media_size',
        'send_at', 'timezone', 'tcpa_script', 'include_optin', 'targets', 'recipients', 'status', 'send_log',
        'recurrence', 'recur_interval', 'recur_until', 'recur_occurrences', 'parent_id', 'recur_index',
        'created_by', 'created_by_name', 'updated_by', 'updated_by_name',
    ];

    protected $casts = [
        'send_at'    => 'datetime',
        'targets'    => 'array',
        'recipients' => 'array',
        'send_log'   => 'array',
        'tcpa_script' => 'boolean',
        'include_optin' => 'boolean',
        'recur_interval' => 'integer',
        'recur_occurrences' => 'integer',
        'recur_index' => 'integer',
        'recur_until' => 'datetime',
    ];
}
