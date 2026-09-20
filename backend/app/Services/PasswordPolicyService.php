<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\PasswordHistory;
use App\Models\Tenant;
use App\Models\TenantAdmin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

/**
 * The single source of truth for passwords: complexity, reuse history,
 * expiry arithmetic, and the audit write.
 *
 * Every flow that sets a password — voluntary change, forced expiry change,
 * forgot-password reset, and admin "Create New Password" — funnels through
 * change(). That is what keeps the rules from drifting between forms and
 * guarantees the expiry clock is refreshed no matter which path fired.
 */
class PasswordPolicyService
{
    /** Complexity: length plus composition. Symbols are allowed, never required. */
    public const MIN_LEN = 8;
    public const MAX_LEN = 200;

    /** Reuse: current password + the last N retired ones are all rejected. */
    public const HISTORY = 5;

    /** Advisory window: start warning this many days out. */
    public const WARN_DAYS = 5;

    /** Per-tenant expiry window bounds. */
    public const DEFAULT_EXPIRY_DAYS = 30;
    public const MIN_EXPIRY_DAYS = 1;
    public const MAX_EXPIRY_DAYS = 365;

    /** Audit trigger enum. */
    public const T_VOLUNTARY = 'voluntary';
    public const T_FORCED = 'forced_expiry';
    public const T_FORGOT = 'forgot_password';
    public const T_ADMIN = 'admin_reset';

    public const TRIGGERS = [self::T_VOLUNTARY, self::T_FORCED, self::T_FORGOT, self::T_ADMIN];

    /** How long the post-login forced-change flag stays valid (seconds). */
    public const RESET_FLAG_TTL = 900;

    // ---------------------------------------------------------------- rules

    /** Helper text shown under every password field, before any failed submit. */
    public static function hint(): string
    {
        return 'At least ' . self::MIN_LEN . ' characters, letters and numbers.';
    }

    /** null when acceptable, otherwise the user-facing reason. */
    public static function complexityError(?string $password): ?string
    {
        $pw = (string) $password;

        if (strlen($pw) < self::MIN_LEN) {
            return 'Password must be at least ' . self::MIN_LEN . ' characters.';
        }
        if (strlen($pw) > self::MAX_LEN) {
            return 'Password must be under ' . self::MAX_LEN . ' characters.';
        }
        if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
            return 'Password must include both letters and numbers.';
        }
        return null;
    }

    /**
     * Rejects the current password and any of the last HISTORY retired ones.
     * Deliberately vague about WHICH one matched.
     */
    public static function reuseError(Model $user, string $userType, ?string $password): ?string
    {
        $pw = (string) $password;
        if ($pw === '') return null;

        if (!empty($user->password_hash) && Hash::check($pw, $user->password_hash)) {
            return "You've used this password recently. Choose a different one.";
        }

        $hashes = PasswordHistory::query()
            ->where('user_type', $userType)
            ->where('user_id', $user->getKey())
            ->pluck('password_hash');

        foreach ($hashes as $hash) {
            if ($hash && Hash::check($pw, $hash)) {
                return "You've used this password recently. Choose a different one.";
            }
        }
        return null;
    }

    // --------------------------------------------------------------- expiry

    /** Clamp a raw tenant setting into the supported range. */
    public static function normalizeDays($days): int
    {
        $n = (int) $days;
        if ($n < self::MIN_EXPIRY_DAYS) return self::MIN_EXPIRY_DAYS;
        if ($n > self::MAX_EXPIRY_DAYS) return self::MAX_EXPIRY_DAYS;
        return $n;
    }

    /**
     * The window to apply to a NEW password: the tenant's value right now.
     * Mid-cycle users keep whatever their password_expiry_days_applied says —
     * changing the tenant setting never rewinds a cycle already in flight.
     */
    public static function expiryDaysFor(?Tenant $tenant): int
    {
        return self::normalizeDays($tenant->password_expiry_days ?? self::DEFAULT_EXPIRY_DAYS);
    }

    public static function tenantFor(Model $user, string $userType): ?Tenant
    {
        if ($user instanceof TenantAdmin) {
            return $user->tenant; // belongsTo(Tenant)
        }
        if ($user instanceof Agent) {
            return Tenant::where('domain', $user->domain)
                ->where('dynalink_user', $user->user)->first();
        }
        // Fall back on the shape of the row rather than the class.
        if (isset($user->tenant_id)) {
            return Tenant::find($user->tenant_id);
        }
        return Tenant::where('domain', $user->domain ?? '')
            ->where('dynalink_user', $user->user ?? '')->first();
    }

    /** Shared expiry snapshot for login / me / warning payloads. */
    public static function stateFor(Model $user): array
    {
        $expires = $user->password_expires_at;

        if (!$expires) {
            return [
                'password_expires_at' => null,
                'password_expired'    => false,
                'password_days_left'  => null,
                'password_warning'    => false,
            ];
        }

        $now = now();
        $expired  = $expires->lte($now);
        // Truncated so "4 days 23 hours" reads as 4 — stays inside the window.
        $daysLeft = (int) $now->diffInDays($expires, false);

        $inWindow = !$expired && $daysLeft <= self::WARN_DAYS;

        // A dismissal is scoped to the exact expires_at it was made for, so a
        // new cycle invalidates it with no cleanup step.
        $dismissedFor = $user->password_expiry_notice_dismissed_for;
        $dismissed = (bool) ($dismissedFor && $dismissedFor->equalTo($expires));

        return [
            'password_expires_at' => $expires->toJSON(),
            'password_expired'    => $expired,
            'password_days_left'  => $daysLeft,
            'password_warning'    => $inWindow && !$dismissed,
        ];
    }

    // ---------------------------------------------------------------- write

    /**
     * Set a new password and start a fresh expiry cycle.
     *
     * Always: recomputes last_changed/expires_at from the tenant's CURRENT
     * window, clears the notice dismissal, retires the old hash into history,
     * bumps session_version, and writes the audit row with its trigger.
     *
     * $ctx keys: domain, actor_type, actor_id, actor_name, ip, detail (array).
     */
    public static function change(Model $user, string $userType, string $plain, string $trigger, array $ctx = []): void
    {
        $tenant = self::tenantFor($user, $userType);
        $days   = self::expiryDaysFor($tenant);
        $now    = now();
        $old    = $user->password_hash;

        $user->forceFill([
            'password_hash'                      => Hash::make($plain),
            'password_last_changed_at'           => $now,
            'password_expires_at'                => (clone $now)->addDays($days),
            'password_expiry_days_applied'       => $days,
            // New cycle -> the old "don't notify again" no longer applies.
            'password_expiry_notice_dismissed_for' => null,
            // Uniform: every other signed-in session drops on next request.
            'session_version'                    => ((int) $user->session_version) + 1,
        ])->save();

        if ($old) {
            PasswordHistory::create([
                'user_type'      => $userType,
                'user_id'        => $user->getKey(),
                'password_hash'  => $old,
                'created_at'     => $now,
            ]);
            self::pruneHistory($userType, $user->getKey());
        }

        self::auditChange($user, $userType, $trigger, $days, $ctx);
    }

    /**
     * Stamp a fresh expiry cycle on a password that was set somewhere else —
     * used when an account is CREATED with its first password. No history
     * entry is written, because there is no previous password to retire.
     *
     * Without this, anyone created after the feature shipped would keep
     * password_expires_at = NULL and never expire at all.
     */
    public static function startCycle(Model $user, string $userType, string $trigger, array $ctx = []): void
    {
        $tenant = self::tenantFor($user, $userType);
        $days   = self::expiryDaysFor($tenant);
        $now    = now();

        $user->forceFill([
            'password_last_changed_at'             => $now,
            'password_expires_at'                  => (clone $now)->addDays($days),
            'password_expiry_days_applied'         => $days,
            'password_expiry_notice_dismissed_for' => null,
        ])->save();

        self::auditChange($user, $userType, $trigger, $days, $ctx);
    }

    protected static function auditChange(Model $user, string $userType, string $trigger, int $days, array $ctx): void
    {
        AuditLog::record(
            $ctx['domain'] ?? ($user->domain ?? null),
            $ctx['actor_type'] ?? 'unknown',
            $ctx['actor_id'] ?? null,
            $ctx['actor_name'] ?? null,
            'password.changed',
            array_merge([
                'trigger'      => in_array($trigger, self::TRIGGERS, true) ? $trigger : self::T_VOLUNTARY,
                'target_type'  => $userType,
                'target_id'    => $user->getKey(),
                'target_name'  => self::displayNameFor($user),
                'expires_at'   => $user->password_expires_at?->toJSON(),
                'days_applied' => $days,
            ], $ctx['detail'] ?? []),
            $ctx['ip'] ?? null
        );
    }

    /** Keep only the newest HISTORY rows (two queries: avoids MySQL 1093). */
    protected static function pruneHistory(string $userType, $userId): void
    {
        $q = PasswordHistory::query()->where('user_type', $userType)->where('user_id', $userId);

        $keep = (clone $q)->orderByDesc('id')->limit(self::HISTORY)->pluck('id')->all();
        if (!$keep) return;

        (clone $q)->whereNotIn('id', $keep)->delete();
    }

    public static function displayNameFor(Model $user): string
    {
        if ($user instanceof Agent || $user instanceof TenantAdmin) {
            return trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
        }
        return (string) ($user->username ?? '');
    }
}
