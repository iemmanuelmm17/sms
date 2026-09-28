<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Per-domain authorized email→SMS sender + assigned SMS numbers. */
class EmailSmsSender extends Model
{
    protected $fillable = ['domain', 'email', 'numbers', 'default_number'];

    protected $casts = ['numbers' => 'array'];

    /** Assigned numbers as digit strings. */
    public function digits(): array
    {
        $out = [];
        foreach ((array) ($this->numbers ?? []) as $n) {
            $d = preg_replace('/\D/', '', (string) $n);
            if ($d !== '') $out[] = $d;
        }
        return array_values(array_unique($out));
    }

    /**
     * Blank-subject outbound number: the explicit default when still
     * assigned, else the lone number. Null = the sender must name a
     * number in the subject.
     */
    public function defaultDigit(): ?string
    {
        $nums = $this->digits();
        $d = preg_replace('/\D/', '', (string) $this->default_number);
        if ($d !== '' && in_array($d, $nums, true)) return $d;
        return count($nums) === 1 ? $nums[0] : null;
    }
}
