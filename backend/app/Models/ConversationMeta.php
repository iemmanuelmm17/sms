<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Local per-conversation state (assignment + pin), keyed by session id. */
class ConversationMeta extends Model
{
    protected $table = 'conversation_meta';

    protected $fillable = ['domain', 'user', 'session_id', 'agent_id', 'identity_id', 'pinned', 'status', 'important'];

    protected $casts = ['pinned' => 'boolean', 'important' => 'boolean'];

    public function agent()
    {
        return $this->belongsTo(Agent::class);
    }
}
