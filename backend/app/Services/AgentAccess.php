<?php

namespace App\Services;

use App\Models\AgentIdentity;
use App\Models\AgentNumberGrant;

/**
 * SINGLE SOURCE OF TRUTH for what an agent may see and send from.
 *
 * TWO distinct sets — do not conflate them:
 *
 *   readableNumbers()  own  ∪  every shared number      → may see the inbox
 *   sendableNumbers()  own  ∪  granted shared numbers   → may send from it
 *
 * sendableNumbers is always a subset of readableNumbers: sharing a line lets
 * the team watch it, a grant is what allows replying on it.
 *
 * Every read path MUST use readableNumbers() and every write path MUST use
 * sendableNumbers(). If a caller computes either set itself they will drift,
 * and a drift on the write side means an agent can send as a number they are
 * not entitled to.
 *
 * Own numbers are derived live from the provider's number map, never stored,
 * so re-provisioning in the portal takes effect with no local edit.
 *
 * A grant alone is not enough — the number must also still be shared. That
 * makes un-sharing an immediate, global revoke without touching grant rows.
 */
class AgentAccess
{
    public function __construct(
        protected DynalinkService $dynalink,
        protected CompanySettingsService $settings,
    ) {}

    /**
     * Numbers (digits) this extension owns, from the provider's dest map.
     *
     * @param array<string,string> $owners digits => dest, from numberOwners()
     * @return list<string>
     */
    public function ownNumbers(array $owners, string $ext): array
    {
        $ext = AgentIdentity::normalizeExt($ext);
        if ($ext === '') return [];

        $out = [];
        foreach ($owners as $digits => $dest) {
            if (AgentIdentity::normalizeExt((string) $dest) === $ext) {
                $out[] = (string) $digits;
            }
        }
        sort($out);
        return $out;
    }

    /**
     * Granted shared numbers, intersected with the live shared flags.
     *
     * @return list<string>
     */
    public function grantedShared(string $domain, string $ext): array
    {
        $ext = AgentIdentity::normalizeExt($ext);
        if ($ext === '') return [];

        $granted = AgentNumberGrant::query()
            ->where('domain', $domain)->where('ext', $ext)
            ->pluck('number')->all();
        if ($granted === []) return [];

        // Intersect with what is actually shared right now.
        $shared = array_flip($this->settings->sharedNumbers($domain));
        $out = [];
        foreach ($granted as $n) {
            $d = AgentNumberGrant::normalizeNumber($n);
            if ($d !== '' && isset($shared[$d])) $out[] = $d;
        }
        sort($out);
        return array_values(array_unique($out));
    }

    /**
     * Numbers this extension may READ (see the inbox for).
     *
     *      own numbers  ∪  every number flagged shared
     *
     * Sharing a number is what makes its inbox visible to the team; a grant is
     * NOT required to look. Grants control SENDING — see sendableNumbers().
     *
     * @return list<string>
     */
    public function readableNumbers(string $domain, string $ext, ?string $token = null, ?array $owners = null): array
    {
        $ext = AgentIdentity::normalizeExt($ext);
        if ($ext === '') return [];
        $identity = AgentIdentity::where('domain', $domain)->where('ext', $ext)->first();
        if ($identity && !$identity->isActive()) return [];   // kill switch

        $owners = $owners ?? $this->ownersOrEmpty($domain, $token, $ext);
        $all = array_merge(
            $this->ownNumbers($owners, $ext),
            array_map(fn($d) => (string) $d, $this->settings->sharedNumbers($domain)),
        );
        $all = array_values(array_unique(array_filter($all)));
        sort($all);
        return $all;
    }

    /**
     * Numbers this extension may SEND FROM.
     *
     *      own numbers  ∪  granted shared numbers (still flagged shared)
     *
     * Strictly a subset of readableNumbers(): an agent can watch a shared
     * inbox without being allowed to reply on it.
     *
     * @deprecated Prefer sendableNumbers(); visibleNumbers() predates the
     *             read/send split and its name no longer says which it is.
     *
     * @param array<string,string>|null $owners pass the cached map to avoid a refetch
     * @return list<string>
     */
    public function sendableNumbers(string $domain, string $ext, ?string $token = null, ?array $owners = null): array
    {
        return $this->visibleNumbers($domain, $ext, $token, $owners);
    }

    /** Owner map, or [] when the provider is unreachable (fail closed). */
    protected function ownersOrEmpty(string $domain, ?string $token, string $ext): array
    {
        try {
            return $this->dynalink->numberOwners((string) $token, $domain);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('AgentAccess: numberOwners failed', [
                'domain' => $domain, 'ext' => $ext, 'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Sendable set (own + granted shared). Disabled agents get nothing.
     *
     * @param array<string,string>|null $owners pass the cached map to avoid a refetch
     * @return list<string>
     */
    public function visibleNumbers(string $domain, string $ext, ?string $token = null, ?array $owners = null): array
    {
        $ext = AgentIdentity::normalizeExt($ext);
        if ($ext === '') return [];

        // Kill switch: a disabled agent has no access at all, regardless of
        // grants or what the provider says they own.
        $identity = AgentIdentity::where('domain', $domain)->where('ext', $ext)->first();
        if ($identity && !$identity->isActive()) return [];

        if ($owners === null) {
            try {
                $owners = $this->dynalink->numberOwners((string) $token, $domain);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('AgentAccess: numberOwners failed', [
                    'domain' => $domain, 'ext' => $ext, 'error' => $e->getMessage(),
                ]);
                $owners = [];
            }
        }

        $all = array_merge(
            $this->ownNumbers($owners, $ext),
            $this->grantedShared($domain, $ext),
        );
        $all = array_values(array_unique($all));
        sort($all);
        return $all;
    }

    /** May this extension SEND from the number? (grant required for shared) */
    public function canUseNumber(string $domain, string $ext, ?string $number, ?string $token = null, ?array $owners = null): bool
    {
        $d = AgentNumberGrant::normalizeNumber($number);
        if ($d === '') return false;
        return in_array($d, $this->sendableNumbers($domain, $ext, $token, $owners), true);
    }

    /** May this extension READ the number's inbox? (sharing alone is enough) */
    public function canReadNumber(string $domain, string $ext, ?string $number, ?string $token = null, ?array $owners = null): bool
    {
        $d = AgentNumberGrant::normalizeNumber($number);
        if ($d === '') return false;
        return in_array($d, $this->readableNumbers($domain, $ext, $token, $owners), true);
    }

    /**
     * Owning extensions to query for this agent's inbox — i.e. the distinct
     * dest values behind their visible numbers. Strictly fewer provider calls
     * than the admin fan-out.
     *
     * @param array<string,string> $owners
     * @return list<string>
     */
    public function visibleExtensions(array $owners, array $visible): array
    {
        $exts = [];
        foreach ($visible as $d) {
            $dest = AgentIdentity::normalizeExt((string) ($owners[$d] ?? ''));
            if ($dest !== '' && !in_array($dest, $exts, true)) $exts[] = $dest;
        }
        return $exts;
    }
}
