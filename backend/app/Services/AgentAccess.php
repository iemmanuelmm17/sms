<?php

namespace App\Services;

use App\Models\AgentIdentity;
use App\Models\AgentNumberGrant;

/**
 * SINGLE SOURCE OF TRUTH for what an agent may see and send from.
 *
 * OWN numbers (from the provider map) always carry full access: view, reply,
 * create. SHARED numbers are controlled by per-number grants:
 *
 *   readableNumbers()   own  ∪  view grants      → may see the inbox
 *   replyableNumbers()  own  ∪  reply grants     → may reply in existing
 *                                                  conversations (same number)
 *   creatableNumbers()  own  ∪  create grants    → may start NEW conversations
 *   sendableNumbers()   own  ∪  reply ∪ create   → legacy "may send from"
 *
 * Every read path MUST use readableNumbers(), reply endpoints MUST use
 * replyableNumbers() and new-conversation endpoints MUST use
 * creatableNumbers(). If a caller computes either set itself they will drift,
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
     * Per-number grant flags for this extension, intersected with the live
     * shared flags (un-sharing pauses a grant without deleting it).
     *
     * @return array<string, array{view: true, reply: bool, create: bool}>
     *         digits => flags; the key's presence IS the view grant.
     */
    public function grantsMap(string $domain, string $ext): array
    {
        $ext = AgentIdentity::normalizeExt($ext);
        if ($ext === '') return [];

        $rows = AgentNumberGrant::query()
            ->where('domain', $domain)->where('ext', $ext)
            ->get();
        if ($rows->isEmpty()) return [];

        $shared = array_flip($this->settings->sharedNumbers($domain));
        $out = [];
        foreach ($rows as $g) {
            $d = AgentNumberGrant::normalizeNumber($g->number);
            if ($d === '' || !isset($shared[$d])) continue;
            $out[$d] = ['view' => true, 'reply' => (bool) $g->reply, 'create' => (bool) $g->create];
        }
        ksort($out);
        return $out;
    }

    /** Granted shared numbers (view) — see grantsMap(). */
    public function grantedShared(string $domain, string $ext): array
    {
        return array_keys($this->grantsMap($domain, $ext));
    }

    /**
     * The three permission sets in one shot (shared provider call).
     *
     * @param array<string,string>|null $owners pass the cached map to avoid a refetch
     * @return array{0: list<string>, 1: list<string>, 2: list<string>}
     *         [readable, replyable, creatable]
     */
    protected function permissionSets(string $domain, string $ext, ?string $token = null, ?array $owners = null): array
    {
        $ext = AgentIdentity::normalizeExt($ext);
        if ($ext === '') return [[], [], []];

        // Kill switch: a disabled agent has no access at all, regardless of
        // grants or what the provider says they own.
        $identity = AgentIdentity::where('domain', $domain)->where('ext', $ext)->first();
        if ($identity && !$identity->isActive()) return [[], [], []];

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

        $own = $this->ownNumbers($owners, $ext);
        $grants = $this->grantsMap($domain, $ext);

        $merge = fn (array $pick) => array_values(array_unique(array_merge($own, $pick)));
        $readable  = $merge(array_keys($grants));
        $replyable = $merge(array_keys(array_filter($grants, fn ($f) => $f['reply'])));
        $creatable = $merge(array_keys(array_filter($grants, fn ($f) => $f['create'])));

        sort($readable); sort($replyable); sort($creatable);
        return [$readable, $replyable, $creatable];
    }

    /**
     * Numbers this extension may READ (see the inbox for).
     *
     *      own numbers  ∪  numbers with a VIEW grant (still flagged shared)
     *
     * @return list<string>
     */
    public function readableNumbers(string $domain, string $ext, ?string $token = null, ?array $owners = null): array
    {
        return $this->permissionSets($domain, $ext, $token, $owners)[0];
    }

    /**
     * Numbers this extension may REPLY FROM — i.e. answer existing
     * conversations on that number (sent as the number itself).
     *
     *      own numbers  ∪  numbers with a REPLY grant
     *
     * @return list<string>
     */
    public function replyableNumbers(string $domain, string $ext, ?string $token = null, ?array $owners = null): array
    {
        return $this->permissionSets($domain, $ext, $token, $owners)[1];
    }

    /**
     * Numbers this extension may START NEW CONVERSATIONS FROM.
     *
     *      own numbers  ∪  numbers with a CREATE grant
     *
     * @return list<string>
     */
    public function creatableNumbers(string $domain, string $ext, ?string $token = null, ?array $owners = null): array
    {
        return $this->permissionSets($domain, $ext, $token, $owners)[2];
    }

    /**
     * Sendable set: own ∪ reply ∪ create grants.
     *
     * @deprecated Prefer replyableNumbers() (reply endpoints) and
     *             creatableNumbers() (new-conversation endpoints); this union
     *             survives for payloads and pre-split call sites.
     *
     * @return list<string>
     */
    public function sendableNumbers(string $domain, string $ext, ?string $token = null, ?array $owners = null): array
    {
        [, $replyable, $creatable] = $this->permissionSets($domain, $ext, $token, $owners);
        $all = array_values(array_unique(array_merge($replyable, $creatable)));
        sort($all);
        return $all;
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

    /** May this extension SEND from the number at all? (reply or create grant) */
    public function canUseNumber(string $domain, string $ext, ?string $number, ?string $token = null, ?array $owners = null): bool
    {
        $d = AgentNumberGrant::normalizeNumber($number);
        if ($d === '') return false;
        return in_array($d, $this->sendableNumbers($domain, $ext, $token, $owners), true);
    }

    /** May this extension READ the number's inbox? (view grant) */
    public function canReadNumber(string $domain, string $ext, ?string $number, ?string $token = null, ?array $owners = null): bool
    {
        $d = AgentNumberGrant::normalizeNumber($number);
        if ($d === '') return false;
        return in_array($d, $this->readableNumbers($domain, $ext, $token, $owners), true);
    }

    /** May this extension REPLY on the number's conversations? (reply grant) */
    public function canReplyNumber(string $domain, string $ext, ?string $number, ?string $token = null, ?array $owners = null): bool
    {
        $d = AgentNumberGrant::normalizeNumber($number);
        if ($d === '') return false;
        return in_array($d, $this->replyableNumbers($domain, $ext, $token, $owners), true);
    }

    /** May this extension START NEW messages from the number? (create grant) */
    public function canCreateNumber(string $domain, string $ext, ?string $number, ?string $token = null, ?array $owners = null): bool
    {
        $d = AgentNumberGrant::normalizeNumber($number);
        if ($d === '') return false;
        return in_array($d, $this->creatableNumbers($domain, $ext, $token, $owners), true);
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
