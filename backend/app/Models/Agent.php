<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Shared-inbox agent: name + tag color shown on assigned conversations. */
class Agent extends Model
{
    protected $fillable = ['domain', 'user', 'first_name', 'last_name', 'tag_color',
        'username', 'password_hash', 'secret_question', 'secret_answer_hash',
        'status', 'default_number', 'allowed_numbers', 'session_version', 'last_seen_at'];

    /** Credentials are write-only: never serialized to any API response. */
    protected $hidden = ['password_hash', 'secret_answer_hash'];

    protected $casts = ['last_seen_at' => 'datetime', 'allowed_numbers' => 'array'];

    /** Effective send-numbers: default first, then allowed extras (de-duped digits). */
    public function assignedNumbers(): array
    {
        $all = array_merge([$this->default_number], (array) ($this->allowed_numbers ?? []));
        $out = [];
        foreach ($all as $n) {
            $d = preg_replace('/\D/', '', (string) $n);
            if ($d !== '' && !in_array($d, $out, true)) $out[] = $d;
        }
        return $out;
    }
}
