<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduledMessage extends Model
{
    protected $fillable = [
        'domain', 'user', 'name', 'message', 'from_number', 'type',
        'media_data', 'media_mime', 'media_size',
        'send_at', 'timezone', 'targets', 'recipients', 'status', 'send_log',
        'created_by', 'created_by_name', 'updated_by', 'updated_by_name',
    ];

    protected $casts = [
        'send_at'    => 'datetime',
        'targets'    => 'array',
        'recipients' => 'array',
        'send_log'   => 'array',
    ];
}
