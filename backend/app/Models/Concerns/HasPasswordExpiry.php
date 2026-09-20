<?php

namespace App\Models\Concerns;

use App\Services\PasswordPolicyService;

/**
 * Password-expiry columns shared by the two local-credential tables
 * (agents and tenant_admins).
 *
 * The columns themselves are declared in each model's $fillable/$casts;
 * this trait only exposes the behaviour so the maths stays in one place.
 */
trait HasPasswordExpiry
{
    /** Which password_histories bucket this model belongs to. */
    abstract public function passwordUserType(): string;

    /** Everything the UI needs: expiry date, days left, expired, warn-now. */
    public function passwordExpiryState(): array
    {
        return PasswordPolicyService::stateFor($this);
    }

    /**
     * Day 0 gate: once password_expires_at has passed, login is blocked and
     * any live session is frozen until the password is replaced.
     */
    public function isPasswordExpired(): bool
    {
        return (bool) ($this->password_expires_at && $this->password_expires_at->lte(now()));
    }

    /** True while inside the advisory pre-expiry window (and not dismissed). */
    public function shouldWarnPasswordExpiry(): bool
    {
        $s = $this->passwordExpiryState();
        return (bool) ($s['password_warning'] ?? false);
    }
}
