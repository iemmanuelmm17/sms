<?php

namespace App\Http\Controllers;

use App\Events\DataChanged;
use App\Services\DynalinkService;
use App\Services\OptOutService;
use App\Http\Controllers\Concerns\ResolvesActor;
use Illuminate\Http\Request;
use App\Models\SentMessageLog;

class MessageSessionController extends Controller
{
    use ResolvesActor;
    public function __construct(protected DynalinkService $dynalink, protected OptOutService $optouts) {}

    /**
     * Threads fetched per inbox load unless the caller asks for more.
     * Matches DynalinkService::NUMBERS_LIMIT so no list in the app is
     * silently clipped by the provider's 100-row default.
     */
    public const DEFAULT_SESSION_LIMIT = 999;

    protected function sess(Request $r): array
    {
        // Actor-shaped session (domain/user/display_name/role). No bearer here —
        // Dynalink call sites use $this->dtoken(request()) so local-only reads
        // never touch the provider.
        return $this->actor($r);
    }

    /**
     * Sessions for an admin, gathered per owning extension.
     *
     * The NS-API scopes message sessions by USER, and a number's sessions live
     * under the extension that number is assigned to ("dest") — not under the
     * admin viewing them. So an admin's inbox is the union of the per-extension
     * session lists for every number on the domain.
     *
     * One slow/failing extension must not blank the whole inbox, so each fetch
     * is isolated and failures are skipped.
     */
    protected function adminSessions(string $token, string $domain, ?int $limit = null, ?string $only = null): array
    {
        $owners = [];
        try {
            $owners = $this->dynalink->numberOwners($token, $domain);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('numberOwners failed; falling back to admin scope',
                ['domain' => $domain, 'error' => $e->getMessage()]);
        }
        // No owner map (provider hiccup, or a domain with no assigned numbers):
        // fall back to the admin's own scope rather than showing an empty inbox.
        if ($owners === []) {
            return $this->dynalink->sessions($token, $domain, $this->sess(request())['user'], $limit);
        }

        // Scoped to one number: query ONLY that number's owning extension.
        // Fanning out across every extension would pull the whole domain's
        // history to then throw almost all of it away.
        if ($only !== null && $only !== '') {
            $ext = $owners[$only] ?? null;
            if ($ext === null) return [];               // unknown/unassigned number
            $out = [];
            foreach ($this->dynalink->sessions($token, $domain, $ext, $limit) as $sess) {
                if (!is_array($sess)) continue;
                // One extension can own several numbers, so still filter.
                if (preg_replace('/\D/', '', (string) ($sess['messagesession-sms-number'] ?? '')) !== $only) continue;
                $sess['_owner_user'] = $ext;
                self::rememberOwner($domain, (string) ($sess['messagesession-id'] ?? ''), $ext);
                $out[] = $sess;
            }
            return $out;
        }

        $all = [];
        $seen = [];
        foreach (array_unique(array_values($owners)) as $ext) {
            try {
                foreach ($this->dynalink->sessions($token, $domain, $ext, $limit) as $sess) {
                    if (!is_array($sess)) continue;
                    $id = (string) ($sess['messagesession-id'] ?? '');
                    if ($id !== '' && isset($seen[$id])) continue;   // number pairs can share a thread
                    if ($id !== '') $seen[$id] = true;
                    $sess['_owner_user'] = $ext;                     // who to call for this thread's messages
                    self::rememberOwner($domain, $id, $ext);
                    $all[] = $sess;
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('session fetch failed for extension',
                    ['domain' => $domain, 'ext' => $ext, 'error' => $e->getMessage()]);
            }
        }
        return $all;
    }

    /**
     * Resolve the NS-API $user that owns a given session id.
     * Admins: the extension assigned to the session's number.
     * Everyone else: their own user.
     */
    /** Memoized per request: send() + setStatus() would otherwise re-scan. */
    protected ?array $ownerIndex = null;

    /**
     * Remember which extension owns a session id.
     *
     * Every session we list already knows its owner, so caching that here
     * means later reads/sends on the SAME thread resolve directly by
     * messagesession id — no number hint, no fan-out, and no risk of picking
     * a different extension than the one the thread was listed under.
     */
    protected static function rememberOwner(string $domain, string $sessionId, string $ext): void
    {
        if ($sessionId === '' || $ext === '') return;
        try {
            \Illuminate\Support\Facades\Cache::put("dl:sowner:{$domain}:{$sessionId}", $ext, 1800);
        } catch (\Throwable $e) { /* cache is an optimisation, never fatal */ }
    }

    protected static function recallOwner(string $domain, string $sessionId): ?string
    {
        try {
            $v = \Illuminate\Support\Facades\Cache::get("dl:sowner:{$domain}:{$sessionId}");
            return is_string($v) && $v !== '' ? $v : null;
        } catch (\Throwable $e) { return null; }
    }

    protected function ownerForSession(Request $request, string $sessionId): string
    {
        $s = $this->sess($request);

        // Portal agents read shared threads owned by OTHER extensions, so they
        // resolve the owner exactly like an admin does. The permission check
        // lives in assertSessionVisible(), not here.
        if (($s['role'] ?? '') !== 'admin' && empty($s['portal_auth'])) return $s['user'];

        // 1. Exact: this session was listed earlier and we recorded its owner.
        //    Keyed on the messagesession itself, so it is always the right
        //    extension even when several numbers share one, or the caller
        //    forgot to send a number hint.
        if ($hit = self::recallOwner($s['domain'], $sessionId)) return $hit;

        // 2. Hint: caller told us which number the thread is on.
        // send() carries the thread's number as from-number; reads use ?number=.
        $hint = preg_replace('/\D/', '', (string) $request->input('number',
            $request->input('from-number', $request->query('number', ''))));
        if ($hint !== '') {
            try {
                $ext = $this->dynalink->numberOwner($this->dtoken($request), $s['domain'], $hint);
                if ($ext !== null) {
                    self::rememberOwner($s['domain'], $sessionId, $ext);
                    return $ext;
                }
            } catch (\Throwable $e) { /* fall through to the scan */ }
        }

        // 3. Last resort: scan every extension to find the thread.
        if ($this->ownerIndex === null) {
            $this->ownerIndex = [];
            $scan = !empty($s['portal_auth'])
                ? $this->agentSessions($request, $s, self::DEFAULT_SESSION_LIMIT, null)
                : $this->adminSessions($this->dtoken($request), $s['domain']);
            foreach ($scan as $sess) {
                $id = (string) ($sess['messagesession-id'] ?? '');
                if ($id !== '') $this->ownerIndex[$id] = (string) ($sess['_owner_user'] ?? $s['user']);
            }
        }
        return $this->ownerIndex[$sessionId] ?? $s['user'];   // unknown thread: fall back rather than fail
    }

    /**
     * Sessions for a portal agent: their own numbers plus granted shared ones.
     *
     * Fetches only the owning extensions behind that visible set — strictly
     * fewer provider calls than the admin fan-out — then filters to the
     * visible numbers, because one extension can own numbers the agent is not
     * entitled to see.
     */
    protected function agentSessions(Request $request, array $s, ?int $limit, ?string $only): array
    {
        $token  = $this->dtoken($request);
        $access = app(\App\Services\AgentAccess::class);

        try {
            $owners = $this->dynalink->numberOwners($token, $s['domain']);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('agentSessions: numberOwners failed',
                ['domain' => $s['domain'], 'error' => $e->getMessage()]);
            return [];   // fail CLOSED: never show an agent an unfiltered inbox
        }

        // Reading follows sharing; sending follows grants (assertAgentNumber).
        $visible = $access->readableNumbers($s['domain'], $s['ext'] ?? $s['user'], $token, $owners);
        if ($only !== null && $only !== '') {
            // Asking for a number outside the visible set yields nothing.
            $visible = in_array($only, $visible, true) ? [$only] : [];
        }
        if ($visible === []) return [];

        $allow = array_flip($visible);
        $out = [];
        $seen = [];
        foreach ($access->visibleExtensions($owners, $visible) as $ext) {
            try {
                foreach ($this->dynalink->sessions($token, $s['domain'], $ext, $limit) as $sess) {
                    if (!is_array($sess)) continue;
                    $d = preg_replace('/\D/', '', (string) ($sess['messagesession-sms-number'] ?? ''));
                    if ($d === '' || !isset($allow[$d])) continue;
                    $id = (string) ($sess['messagesession-id'] ?? '');
                    if ($id !== '' && isset($seen[$id])) continue;
                    if ($id !== '') $seen[$id] = true;
                    $sess['_owner_user'] = $ext;
                    self::rememberOwner($s['domain'], $id, $ext);
                    $out[] = $sess;
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('agentSessions: fetch failed',
                    ['domain' => $s['domain'], 'ext' => $ext, 'error' => $e->getMessage()]);
            }
        }
        return $out;
    }

    /**
     * Every queued conversation the caller may see, across all numbers.
     *
     * conversation_meta already knows WHICH sessions are queued, so rather than
     * fanning out across the whole domain we only query the extensions that
     * actually own a queued thread — usually a small subset. Agents are then
     * narrowed to their readable numbers.
     */
    protected function queuedSessions(Request $request, array $s, ?int $limit): array
    {
        $ids = \App\Models\ConversationMeta::where('domain', $s['domain'])
            ->where('status', 'queued')->pluck('session_id')->all();
        if ($ids === []) return [];
        $want = array_flip(array_map('strval', $ids));

        $token = $this->dtoken($request);
        try {
            $owners = $this->dynalink->numberOwners($token, $s['domain']);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('queuedSessions: numberOwners failed',
                ['domain' => $s['domain'], 'error' => $e->getMessage()]);
            return [];   // fail closed
        }

        // Agents only ever see queued threads on numbers they can read.
        $allow = null;
        if (!empty($s['portal_auth'])) {
            $allow = array_flip(app(\App\Services\AgentAccess::class)
                ->readableNumbers($s['domain'], $s['ext'] ?? $s['user'], $token, $owners));
            if ($allow === []) return [];
        }

        // Prefer the cached owner per session; fall back to every extension.
        $exts = [];
        foreach ($ids as $sid) {
            $o = self::recallOwner($s['domain'], (string) $sid);
            if ($o !== null && !in_array($o, $exts, true)) $exts[] = $o;
        }
        if ($exts === []) $exts = array_values(array_unique(array_values($owners)));

        $out = [];
        $seen = [];
        foreach ($exts as $ext) {
            try {
                foreach ($this->dynalink->sessions($token, $s['domain'], $ext, $limit) as $sess) {
                    if (!is_array($sess)) continue;
                    $id = (string) ($sess['messagesession-id'] ?? '');
                    if ($id === '' || !isset($want[$id]) || isset($seen[$id])) continue;
                    $num = preg_replace('/\D/', '', (string) ($sess['messagesession-sms-number'] ?? ''));
                    if ($allow !== null && ($num === '' || !isset($allow[$num]))) continue;
                    $seen[$id] = true;
                    $sess['_owner_user'] = $ext;
                    self::rememberOwner($s['domain'], $id, $ext);
                    $out[] = $sess;
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('queuedSessions: fetch failed',
                    ['domain' => $s['domain'], 'ext' => $ext, 'error' => $e->getMessage()]);
            }
        }
        return $out;
    }

    /** GET /api/sessions — list all message sessions. */
    public function index(Request $request)
    {
        $s = $this->sess($request);
        // Default cap: an inbox view only ever renders a page of threads, so
        // pulling unbounded history on every load is wasted latency. Callers
        // can raise it explicitly.
        $limit = $request->integer('limit') ?: self::DEFAULT_SESSION_LIMIT;
        // ?number= scopes the inbox to a single SMS number. Admins default to
        // the tenant main number rather than every number on the domain, which
        // would be a fan-out across every extension on first paint.
        $only = preg_replace('/\D/', '', (string) $request->query('number', ''));

        // ?scope=queued — the queue is a shared worklist spanning every number,
        // so it cannot use the single-number inbox fetch.
        if ($request->query('scope') === 'queued') {
            return response()->json($this->queuedSessions($request, $s, $limit));
        }

        if (($s['role'] ?? '') === 'admin') {
            $sessions = $this->adminSessions($this->dtoken(request()), $s['domain'], $limit, $only ?: null);
        } elseif (!empty($s['portal_auth'])) {
            $sessions = $this->agentSessions($request, $s, $limit, $only ?: null);
        } else {
            $sessions = $this->dynalink->sessions($this->dtoken(request()), $s['domain'], $s['user'], $limit);
        }

        // Newest first
        usort($sessions, fn($a, $b) => strcmp(
            $b['messagesession-last-datetime'] ?? '', $a['messagesession-last-datetime'] ?? ''
        ));

        // Agents see shared-number threads plus threads on their own numbers
        // (filter AFTER the cache read — the cached list stays tenant-wide).
        if (($s['role'] ?? '') === 'agent' && empty($s['portal_auth']) && !empty($s['agent_id'])
            && $agent = \App\Models\Agent::find($s['agent_id'])) {
            try {
                $allow = array_flip(array_merge($agent->assignedNumbers(),
                    app(\App\Services\CompanySettingsService::class)->sharedNumbers($s['domain'])));
                $sessions = array_values(array_filter($sessions, function ($sess) use ($allow) {
                    $d = preg_replace('/\D/', '', (string) ($sess['messagesession-sms-number'] ?? ''));
                    return $d !== '' && isset($allow[$d]);
                }));
            } catch (\Throwable $e) { /* fail-open: leave unfiltered */ }
        }

        return response()->json($sessions);
    }

    /**
     * Refuse per-thread access to a number outside a portal agent's visible
     * set. Without this an agent could read or reply to ANY thread on the
     * domain by guessing a session id, since every call runs on the tenant
     * superadmin token.
     */
    protected function assertSessionVisible(Request $request, string $sessionId): void
    {
        $s = $this->sess($request);
        if (empty($s['portal_auth'])) return;

        $token = $this->dtoken($request);
        $access = app(\App\Services\AgentAccess::class);

        // Prefer the number the caller named; otherwise find the thread.
        $num = preg_replace('/\D/', '', (string) $request->input('number',
            $request->input('from-number', $request->query('number', ''))));

        if ($num === '') {
            foreach ($this->agentSessions($request, $s, self::DEFAULT_SESSION_LIMIT, null) as $sess) {
                if ((string) ($sess['messagesession-id'] ?? '') === $sessionId) {
                    $num = preg_replace('/\D/', '', (string) ($sess['messagesession-sms-number'] ?? ''));
                    break;
                }
            }
            // Not in the agent's own listing → not theirs to open.
            if ($num === '') {
                \Illuminate\Support\Facades\Log::warning('session read 403: thread not in agent listing', [
                    'domain' => $s['domain'], 'ext' => $s['ext'] ?? $s['user'], 'session' => $sessionId,
                ]);
                abort(response()->json(['message' => 'This conversation is not available to you.'], 403));
            }
        }

        $ok = $access->canReadNumber($s['domain'], $s['ext'] ?? $s['user'], $num, $token);
        if (!$ok) {
            // Leave a trail: this fires either when a grant/assignment was
            // revoked (correct) or when the provider owner map came back
            // empty mid-hiccup (spurious) — the log tells them apart.
            \Illuminate\Support\Facades\Log::warning('session read 403: number not readable by agent', [
                'domain' => $s['domain'], 'ext' => $s['ext'] ?? $s['user'],
                'session' => $sessionId, 'number' => $num,
                'shared' => app(\App\Services\CompanySettingsService::class)->sharedNumbers($s['domain']),
            ]);
        }
        abort_unless(
            $ok,
            response()->json(['message' => 'This conversation is not available to you.'], 403)
        );
    }

    /** GET /api/sessions/{id}/messages */
    public function messages(Request $request, string $id)
    {
        $s = $this->sess($request);
        $this->assertSessionVisible($request, $id);
        return response()->json($this->normalizeStaleStatuses(
            $this->dynalink->sessionMessages($this->dtoken(request()), $s['domain'],
                $this->ownerForSession($request, $id), $id)
        ));
    }

    /**
     * The provider parks outbound history at 'sending'/'scheduled' forever —
     * delivery receipts only arrive by webhook, which a LAN box never gets.
     * Anything outbound older than 5 minutes demonstrably went out (this is
     * the same rule the web client applied locally); normalizing here fixes
     * it once for every client instead of per-render.
     */
    protected function normalizeStaleStatuses(array $list): array
    {
        foreach ($list as &$m) {
            if (!is_array($m) || ($m['direction'] ?? '') !== 'term') continue;
            if (!preg_match('/^(sending|pending|queued|scheduled)$/i', (string) ($m['status'] ?? ''))) continue;
            try {
                $ts = \Illuminate\Support\Carbon::parse((string) ($m['timestamp'] ?? ''), 'UTC');
            } catch (\Throwable $e) {
                continue; // unparseable timestamp — leave the status alone
            }
            if ($ts->lt(now()->subMinutes(5))) $m['status'] = 'delivered';
        }
        unset($m);
        return $list;
    }

    /**
     * POST /api/sessions/{id}/messages — send SMS/MMS inside a session.
     * Body: { message, from-number, destination?: string|string[], type?: sms|mms,
     *         data?: base64, mime-type?: ..., size?: ... }
     */
    public function send(Request $request, string $id)
    {
        $s = $this->sess($request);
        $data = $request->validate([
            'message'      => 'required|string|max:5000',
            'from-number'  => 'required|string',
            'destination'  => 'sometimes',
            'type'         => 'sometimes|in:sms,mms',
            'data'         => 'sometimes|string',       // base64 for MMS
            'mime-type'    => 'sometimes|string',       // image/png|jpg|gif...
            'size'         => 'sometimes',
        ]);

        $this->assertAgentNumber($request, (string) ($data['from-number'] ?? ''), 'reply'); // in-session reply
        $data['message'] = app(\App\Services\CompanySettingsService::class)->resolve($s['domain'], $data['message'], (string) ($this->actor($request)['display_name'] ?? ''));
        $data['message'] = $this->appendAgentSignature($s, (string) ($data['from-number'] ?? ''), $data['message']);

        // TCPA: never send to opted-out numbers.
        $check = isset($data['destination']) ? (array) $data['destination'] : [];
        if (empty($check)) {
            // In-session reply without explicit destination — resolve the remote.
            try {
                foreach ($this->dynalink->sessions($this->dtoken(request()), $s['domain'],
                    $this->ownerForSession($request, $id)) as $sess) {
                    if ((string) ($sess['messagesession-id'] ?? '') === (string) $id) {
                        $check = [$sess['messagesession-remote'] ?? ''];
                        break;
                    }
                }
            } catch (\Throwable $e) { /* fail open; explicit paths are covered */ }
        }
        foreach ($check as $dest) {
            if ($dest !== '' && $this->optouts->isOptedOut($s['domain'], (string) $dest, (string) $data['from-number'])) {
                return response()->json(['message' => 'Blocked: this number opted out (do-not-contact).'], 422);
            }
        }

        $payload = [
            'type'        => $data['type'] ?? 'sms',
            'message'     => $data['message'],
            'from-number' => $data['from-number'],
        ];
        if (isset($data['destination'])) $payload['destination'] = $data['destination'];
        foreach (['data', 'mime-type', 'size'] as $k) {
            if (isset($data[$k])) $payload[$k] = $data[$k];
        }

        [$status, $body] = $this->dynalink->sendInSession(
            $this->dtoken(request()), $s['domain'], $this->ownerForSession($request, $id), $id, $payload
        );

        if ($status >= 200 && $status < 300) {
            if (!empty($s['portal_auth'])) {
                $owner = $this->ownerForSession($request, $id);
                \App\Models\AuditLog::record($s['domain'], 'agent', $s['identity_id'] ?? null,
                    $s['display_name'] ?? ($s['ext'] ?? ''), 'agent.message.sent', [
                        'session_id'   => (string) $id,
                        'from_number'  => (string) ($data['from-number'] ?? ''),
                        'sent_as_ext'  => $owner,
                        // true when replying on someone else's (shared) number
                        'shared'       => $owner !== ($s['ext'] ?? $s['user']),
                    ], $request->ip());
            }
            DataChanged::send($s['domain'], $s['user'], 'sessions', 'message-sent', $id, ['session_id' => $id]);
            \App\Services\OnboardingService::markAgentStep($s, 'first_send');
            $toDigits = preg_replace('/\D/', '', (string) ($check[0] ?? ''));
            SentMessageLog::record([
                'tenant_id' => SentMessageLog::scopeTenant($s),
                'domain' => $s['domain'], 'user' => $s['user'],
                'agent_id' => ($s['role'] ?? null) === 'agent' ? ($s['agent_id'] ?? null) : null,
                'actor_name' => $s['display_name'] ?? $s['username'] ?? null,
                'category' => SentMessageLog::REGULAR_REPLY,
                'session_id' => (string) $id,
                // Store digits only: the agent report filters on digits, and a
                // formatted value here silently matched nothing.
                'from_number' => preg_replace('/\D/', '', (string) ($data['from-number'] ?? '')),
                'to_number' => $toDigits !== '' ? $toDigits : null,
                'type' => $data['type'] ?? 'sms',
            ]);
        }
        return response()->json($body, $status);
    }

    /**
     * Append "— First L." for agent sends on numbers with the signature
     * enabled (per-number opt-in, default off).
     *
     * Deliberately not global: the signature is permanent and public, and
     * costs SMS segments — ~12 chars can push a message near the 160-char
     * boundary into a second segment, doubling its cost.
     */
    protected function appendAgentSignature(array $s, string $fromNumber, string $message): string
    {
        if (empty($s['portal_auth'])) return $message;

        $digits = preg_replace('/\D/', '', $fromNumber);
        if ($digits === '') return $message;

        $meta = app(\App\Services\CompanySettingsService::class)->get($s['domain'])['number_meta'][$digits] ?? [];
        if (empty($meta['signature'])) return $message;

        $sig = self::signatureFor((string) ($s['display_name'] ?? ''));
        if ($sig === '' || str_ends_with(rtrim($message), $sig)) return $message;

        return rtrim($message) . "\n" . $sig;
    }

    /**
     * "Maria Santos" => "— Maria S."   "Maria" => "— Maria"
     *
     * Takes the initial of the SECOND word, not the last: portal surnames
     * often carry a suffix ("Johnson ACD"), and using the last word turned
     * Alex Johnson ACD into "— Alex A." instead of "— Alex J.".
     */
    public static function signatureFor(string $displayName): string
    {
        $parts = preg_split('/\s+/', trim($displayName)) ?: [];
        $parts = array_values(array_filter($parts, fn($p) => $p !== ''));
        if ($parts === []) return '';
        $first = $parts[0];
        if (count($parts) === 1) return '— ' . $first;
        $initial = mb_strtoupper(mb_substr($parts[1], 0, 1));
        return '— ' . $first . ' ' . $initial . '.';
    }

    /** POST /api/sessions/{id}/read — mark read in the portal + sync instances. */
    public function read(Request $request, string $id)
    {
        return $this->setStatus($request, $id, 'read');
    }

    /** POST /api/sessions/{id}/unread — mark unread in the portal + sync instances. */
    public function unread(Request $request, string $id)
    {
        return $this->setStatus($request, $id, 'unread');
    }

    protected function setStatus(Request $request, string $id, string $status)
    {
        $this->assertSessionVisible($request, $id);
        $s = $this->sess($request);
        [$code] = $this->dynalink->setSessionStatus($this->dtoken(request()), $s['domain'],
            $this->ownerForSession($request, $id), $id, $status);
        if ($code < 200 || $code >= 300) {
            return response()->json(['message' => "Portal update failed (HTTP {$code})."], 502);
        }
        DataChanged::send($s['domain'], $s['user'], 'sessions', $status, $id);
        return response()->json(['ok' => true]);
    }
}
