<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Local copy of a Dynalink address-book entry.
 *
 * The rest of the app consumes contacts in the PROVIDER's shape
 * (`unique-id`, `name-first-name`, `phonenumber-cell`, …), so every read goes
 * through toProviderArray() — nothing downstream has to change.
 */
class Contact extends Model
{
    protected $fillable = [
        'domain', 'user', 'provider_id',
        'first_name', 'middle_name', 'last_name', 'email', 'company',
        'phone_work', 'phone_cell', 'phone_home', 'phone_fax',
        'raw', 'synced_at', 'pushed_at',
    ];

    protected $casts = [
        'raw'       => 'array',
        'synced_at' => 'datetime',
        'pushed_at' => 'datetime',
    ];

    /** Provider-shaped row: mapped fields win, unknown provider fields survive. */
    public function toProviderArray(): array
    {
        $raw = is_array($this->raw) ? $this->raw : [];
        return array_merge($raw, [
            'unique-id'         => $this->provider_id ?? '',
            'name-first-name'   => (string) ($this->first_name ?? ''),
            'name-middle-name'  => (string) ($this->middle_name ?? ''),
            'name-last-name'    => (string) ($this->last_name ?? ''),
            'email'             => (string) ($this->email ?? ''),
            'company'           => (string) ($this->company ?? ''),
            'phonenumber-work'  => (string) ($this->phone_work ?? ''),
            'phonenumber-cell'  => (string) ($this->phone_cell ?? ''),
            'phonenumber-home'  => (string) ($this->phone_home ?? ''),
            'phonenumber-fax'   => (string) ($this->phone_fax ?? ''),
        ]);
    }

    /** Payload for a portal create/update call. */
    public function toProviderPayload(): array
    {
        return [
            'name-first-name'  => (string) ($this->first_name ?? ''),
            'name-middle-name' => (string) ($this->middle_name ?? ''),
            'name-last-name'   => (string) ($this->last_name ?? ''),
            'email'            => (string) ($this->email ?? ''),
            'company'          => (string) ($this->company ?? ''),
            'phonenumber-work' => (string) ($this->phone_work ?? ''),
            'phonenumber-cell' => (string) ($this->phone_cell ?? ''),
            'phonenumber-home' => (string) ($this->phone_home ?? ''),
            'phonenumber-fax'  => (string) ($this->phone_fax ?? ''),
        ];
    }

    /** Digits-only cell — the dedupe key when the portal gave us no id. */
    public function cellDigits(): string
    {
        return preg_replace('/\D/', '', (string) ($this->phone_cell ?? '')) ?? '';
    }
}
