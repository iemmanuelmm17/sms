<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OptEvent extends Model
{
    protected $fillable = [
        'domain', 'phone_number', 'contact_id', 'direction', 'keyword', 'occurred_at',
    ];

    protected $casts = ['occurred_at' => 'datetime'];
}
