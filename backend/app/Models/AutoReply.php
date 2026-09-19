<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutoReply extends Model
{
    protected $fillable = [
        'domain', 'user', 'name', 'keywords', 'match_mode',
        'message', 'from_number', 'active', 'trigger_count', 'last_triggered_at',
        'is_default', 'is_deletable', 'default_key', 'default_body', 'default_keywords',
        'created_by', 'created_by_name', 'updated_by', 'updated_by_name',
    ];

    protected $casts = [
        'keywords'          => 'array',
        'active'            => 'boolean',
        'is_default'        => 'boolean',
        'is_deletable'      => 'boolean',
        'default_keywords'  => 'array',
        'last_triggered_at' => 'datetime',
    ];

    public function logs()
    {
        return $this->hasMany(AutoReplyLog::class);
    }
}
