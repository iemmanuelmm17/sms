<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutoReplyLog extends Model
{
    protected $fillable = [
        'auto_reply_id', 'domain', 'user', 'from_number',
        'matched_keyword', 'status', 'detail',
    ];

    public function rule()
    {
        return $this->belongsTo(AutoReply::class, 'auto_reply_id');
    }
}
