<?php

namespace App\Http\Controllers;

use App\Events\DataChanged;
use App\Models\AutoReply;
use App\Models\AutoReplyLog;
use App\Services\AutoReplyService;
use App\Services\DynalinkService;
use App\Http\Controllers\Concerns\ResolvesActor;
use Illuminate\Http\Request;

class AutoReplyController extends Controller
{
    use ResolvesActor;
    protected function scope(Request $r): array
    {
        $a = $this->actor($r);
        return [$a['domain'], $a['user']];
    }

    /**
     * TCPA words the compliance engine owns (OptOutService::STOP_WORDS +
     * START_WORDS). These always win — the default actions answer them and
     * priority never overrides that, so a custom rule claiming one would be
     * dead weight. Rejected up front instead.
     */
    public const RESERVED_KEYWORDS = [
        'stop', 'stopall', 'unsubscribe', 'unsubscribed', 'cancel', 'end', 'quit',
        'start', 'subscribed', 'yes', 'unstop',
    ];

    /** 422 unless every keyword is outside the TCPA-reserved set. */
    protected function assertNotReserved(array $keywords): void
    {
        foreach ($keywords as $k) {
            $n = strtolower(trim((string) $k, " \t\n\r\0\x0B!?.\"'"));
            if (in_array($n, self::RESERVED_KEYWORDS, true)) {
                abort(response()->json(['message' => strtoupper($n)
                    . ' is reserved for TCPA compliance — the default STOP/START actions own it and always take priority. Choose a different keyword.'], 422));
            }
        }
    }

    /** The two permanent compliance actions (Action A = opt-out, B = opt-in). */
    public const DEFAULT_A_BODY = '$CompanyName: Notifications stopped. Reply START to subscribe.';
    public const DEFAULT_B_BODY = '$CompanyName: Thanks for signing up for updates! Msg frequency varies. Msg&Data rates may apply. Reply STOP or UNSUBSCRIBE to cancel.';

    /** Agent rule visibility: null for admins; [agent, creators, main] for agents. */
    protected function agentRuleContext(Request $request, string $domain, string $user): ?array
    {
        $actor = $this->actor($request);
        if (($actor['role'] ?? '') !== 'agent' || empty($actor['agent_id'])) return null;
        $me = \App\Models\Agent::find($actor['agent_id']);
        if (!$me) abort(403);
        $mine = $me->assignedNumbers();
        $shared = [];
        try { $shared = app(\App\Services\CompanySettingsService::class)->sharedNumbers($domain); } catch (\Throwable $e) {}
        $myShared = array_intersect($mine, $shared);
        $creators = ['agent:' . $me->id];
        if ($myShared !== []) {
            foreach (\App\Models\Agent::where('domain', $domain)->where('user', $user)->where('status', 'active')->get() as $ag) {
                if ($ag->id === $me->id) continue;
                if (array_intersect($ag->assignedNumbers(), $myShared) !== []) $creators[] = 'agent:' . $ag->id;
            }
        }
        $main = '';
        try { $main = preg_replace('/\D/', '', (string) (\App\Models\Tenant::where('domain', $domain)->where('dynalink_user', $user)->value('main_number') ?? '')); } catch (\Throwable $e) {}
        return [$me, $creators, $main];
    }

    protected function ruleVisibleToAgent(AutoReply $rule, array $creators): bool
    {
        if ($rule->is_default) return true;
        $cb = (string) ($rule->created_by ?? '');
        if (!str_starts_with($cb, 'agent:')) return true; // admin rule: read-only
        return in_array($cb, $creators, true);
    }

    /** GET /api/auto-replies (defaults pinned first; auto-created). */
    public function index(Request $request)
    {
        [$domain, $user] = $this->scope($request);
        $this->ensureDefaults($domain, $user);
        $q = AutoReply::where('domain', $domain)->where('user', $user)
            ->orderBy('priority')->orderBy('id'); // priority order = evaluation order
        if ($ctx = $this->agentRuleContext($request, $domain, $user)) {
            return response()->json($this->decorate($q->get()->filter(fn($r) => $this->ruleVisibleToAgent($r, $ctx[1]))->values(), $domain, $user));
        }
        return response()->json($this->decorate($q->get(), $domain, $user));
    }

    protected function ensureDefaults(string $domain, string $user): void
    {
        $defs = [
            [
                'default_key' => 'opt_out', 'name' => 'Opt-out (STOP)',
                'keywords' => ['STOP', 'UNSUBSCRIBED'], 'message' => self::DEFAULT_A_BODY,
            ],
            [
                'default_key' => 'opt_in', 'name' => 'Opt-in (START)',
                'keywords' => ['START', 'SUBSCRIBED'], 'message' => self::DEFAULT_B_BODY,
            ],
        ];
        foreach ($defs as $d) {
            AutoReply::firstOrCreate(
                ['domain' => $domain, 'user' => $user, 'default_key' => $d['default_key']],
                [
                    'name' => $d['name'], 'keywords' => $d['keywords'], 'match_mode' => 'exact',
                    'message' => $d['message'], 'from_number' => null, 'active' => true,
                    'is_default' => true, 'is_deletable' => false,
                    'default_body' => $d['message'], 'default_keywords' => $d['keywords'],
                ]
            );
        }
    }

    /** POST /api/auto-replies — keywords accepts array or comma-separated string. */
    public function store(Request $request)
    {
        [$domain, $user] = $this->scope($request);
        $ctx = $this->agentRuleContext($request, $domain, $user);
        if (!$ctx) $this->requireAdmin($request);
        $data = $request->validate([
            'name'        => 'required|string|max:120',
            'match_type'  => 'sometimes|in:keyword,any',
            'keywords'    => $request->input('match_type') === AutoReply::MATCH_ANY ? 'sometimes|nullable' : 'required',
            'match_mode'  => 'sometimes|in:any,all,exact',
            'message'     => 'required|string|max:2000',
            'from_number' => 'sometimes|nullable|string|max:30',
            'active'      => 'sometimes|boolean',
            'numbers'     => 'sometimes|nullable|array|max:100',
            'numbers.*'   => 'string|max:32',
            'active_from' => 'sometimes|nullable|date_format:H:i',
            'active_to'   => 'sometimes|nullable|date_format:H:i',
            'active_days' => 'sometimes|nullable|array|max:7',
            'active_days.*' => 'integer|between:0,6',
            'timezone'    => 'sometimes|nullable|string|max:64',
            'schedule'    => 'sometimes|nullable',
        ]);
        $data = $this->normalizeScope($data);
        $data = $this->normalizeCatchAll($data);
        if (($data['match_type'] ?? AutoReply::MATCH_KEYWORD) !== AutoReply::MATCH_ANY) {
            $data['keywords'] = $this->normalizeKeywords($data['keywords'] ?? '');
            if (empty($data['keywords'])) {
                return response()->json(['message' => 'At least one keyword is required.'], 422);
            }
            $this->assertNotReserved($data['keywords']); // TCPA words belong to the defaults
        }
        if ($ctx) {
            [$agent, , $main] = $ctx;
            $fromDigits = preg_replace('/\D/', '', (string) ($data['from_number'] ?? ''));
            $ok = $fromDigits === '' || ($fromDigits !== $main && in_array($fromDigits, $agent->assignedNumbers(), true));
            if (!$ok) return response()->json(['message' => 'Agent rules may only send from your assigned numbers (not the main line).'], 422);
            $data['from_number'] = $fromDigits !== '' ? $fromDigits : null;
        }
        if ($ctx) {
            $scoped = $this->applyAgentScope($data['numbers'] ?? null, $ctx);
            if (is_string($scoped)) return response()->json(['message' => $scoped], 422);
            if (is_array($scoped)) $data['numbers'] = $scoped;
        }
        [$cb, $cbName] = $ctx
            ? ['agent:' . $ctx[0]->id, trim($ctx[0]->first_name . ' ' . $ctx[0]->last_name)]
            : [$user, $request->session()->get('dynalink.display_name')];
        $rule = AutoReply::create($data + [
            'domain' => $domain, 'user' => $user, 'match_mode' => $data['match_mode'] ?? 'any',
            'priority' => (int) (AutoReply::where('domain', $domain)->where('user', $user)->max('priority') ?? -1) + 1,
            'is_default' => false, 'is_deletable' => true,
            'created_by' => $cb, 'created_by_name' => $cbName,
            'updated_by' => $cb, 'updated_by_name' => $cbName]);
        $this->audit($request, 'autoreply.created', ['autoreply_id' => $rule->id, 'name' => $rule->name]);
        DataChanged::send($domain, $user, 'auto-replies', 'saved', $rule->id);
        return response()->json($rule, 201);
    }

    /** GET /api/auto-replies/{autoReply} */
    public function show(Request $request, AutoReply $autoReply)
    {
        [$domain] = $this->scope($request);
        abort_unless($autoReply->domain === $domain, 403);
        if ($ctx = $this->agentRuleContext($request, $autoReply->domain, $autoReply->user)) {
            abort_unless($this->ruleVisibleToAgent($autoReply, $ctx[1]), 403);
        }
        return response()->json($this->decorate([$autoReply], $autoReply->domain, $autoReply->user)[0]);
    }

    /** PUT /api/auto-replies/{autoReply} */
    public function update(Request $request, AutoReply $autoReply)
    {
        [$domain, $user] = $this->scope($request);
        abort_unless($autoReply->domain === $domain, 403);
        $ctx = $this->agentRuleContext($request, $domain, $user);
        if ($ctx) {
            [$agent] = $ctx;
            if ($autoReply->is_default || (string) $autoReply->created_by !== 'agent:' . $agent->id) abort(403);
        } else {
            $this->requireAdmin($request);
        }
        if ($autoReply->is_default) {
            // Editing a default action requires the domain name as password.
            $pw = (string) $request->input('_password', '');
            if ($pw === '' || strcasecmp($pw, $domain) !== 0) {
                return response()->json(['message' => 'Incorrect unlock code.'], 403);
            }
        }
        $data = $request->validate([
            'name'        => 'sometimes|string|max:120',
            'match_type'  => 'sometimes|in:keyword,any',
            'keywords'    => 'sometimes',
            'match_mode'  => 'sometimes|in:any,all,exact',
            'message'     => 'sometimes|string|max:2000',
            'from_number' => 'sometimes|nullable|string|max:30',
            'active'      => 'sometimes|boolean',
            'numbers'     => 'sometimes|nullable|array|max:100',
            'numbers.*'   => 'string|max:32',
            'active_from' => 'sometimes|nullable|date_format:H:i',
            'active_to'   => 'sometimes|nullable|date_format:H:i',
            'active_days' => 'sometimes|nullable|array|max:7',
            'active_days.*' => 'integer|between:0,6',
            'timezone'    => 'sometimes|nullable|string|max:64',
            'schedule'    => 'sometimes|nullable',
        ]);
        $data = $this->normalizeScope($data);
        $data = $this->normalizeCatchAll($data);
        if (isset($data['keywords']) && ($data['match_type'] ?? $autoReply->match_type) !== AutoReply::MATCH_ANY) {
            $data['keywords'] = $this->normalizeKeywords($data['keywords']);
            if (empty($data['keywords'])) {
                return response()->json(['message' => 'At least one keyword is required.'], 422);
            }
            if (!$autoReply->is_default) {
                $this->assertNotReserved($data['keywords']); // defaults own these
            }
        }
        if ($ctx && array_key_exists('from_number', $data)) {
            [$agent, , $main] = $ctx;
            $fromDigits = preg_replace('/\D/', '', (string) ($data['from_number'] ?? ''));
            $ok = $fromDigits === '' || ($fromDigits !== $main && in_array($fromDigits, $agent->assignedNumbers(), true));
            if (!$ok) return response()->json(['message' => 'Agent rules may only send from your assigned numbers (not the main line).'], 422);
            $data['from_number'] = $fromDigits !== '' ? $fromDigits : null;
        }
        if ($ctx && array_key_exists('numbers', $data)) {
            $scoped = $this->applyAgentScope($data['numbers'], $ctx);
            if (is_string($scoped)) return response()->json(['message' => $scoped], 422);
            if (is_array($scoped)) $data['numbers'] = $scoped;
        }
        if ($autoReply->is_default) {
            // Defaults always match the exact keyword; flags can't be flipped.
            unset($data['is_default'], $data['is_deletable'], $data['default_key'], $data['default_body'], $data['default_keywords']);
            $data['match_mode'] = 'exact';
        }
        if ($ctx) {
            $data['updated_by'] = 'agent:' . $ctx[0]->id;
            $data['updated_by_name'] = trim($ctx[0]->first_name . ' ' . $ctx[0]->last_name);
        } else {
            $data['updated_by'] = $user;
            $data['updated_by_name'] = $request->session()->get('dynalink.display_name');
        }
        $autoReply->update($data);
        $this->audit($request, 'autoreply.updated', ['autoreply_id' => $autoReply->id, 'name' => $autoReply->name, 'keys' => array_values(array_diff(array_keys($data), ['updated_by', 'updated_by_name']))]);
        DataChanged::send($domain, $user, 'auto-replies', 'saved', $autoReply->id);
        return response()->json($autoReply);
    }

    /** DELETE /api/auto-replies/{autoReply} */
    public function destroy(Request $request, AutoReply $autoReply)
    {
        [$domain, $user] = $this->scope($request);
        abort_unless($autoReply->domain === $domain, 403);
        if ($ctx = $this->agentRuleContext($request, $domain, $user)) {
            if ($autoReply->is_default || (string) $autoReply->created_by !== 'agent:' . $ctx[0]->id) abort(403);
        } else {
            $this->requireAdmin($request);
        }
        abort_unless($autoReply->is_deletable, 403, 'Default actions cannot be deleted.');
        $id = $autoReply->id; $nm = $autoReply->name;
        $autoReply->delete();
        $this->audit($request, 'autoreply.deleted', ['autoreply_id' => $id, 'name' => $nm]);
        DataChanged::send($domain, $user, 'auto-replies', 'deleted', $id);
        return response()->json(['ok' => true]);
    }

    /** GET /api/auto-reply-logs?rule_id=&limit= */
    public function logs(Request $request)
    {
        [$domain, $user] = $this->scope($request);
        $q = AutoReplyLog::with('rule:id,name')
            ->where('domain', $domain)->where('user', $user)
            ->orderByDesc('id');
        if ($request->filled('rule_id')) $q->where('auto_reply_id', $request->input('rule_id'));
        return response()->json($q->limit(min((int) $request->input('limit', 50), 200))->get());
    }

    /** POST /api/auto-replies/test { text } — dry-run match, sends nothing. */
    public function test(Request $request, AutoReplyService $svc)
    {
        [$domain, $user] = $this->scope($request);
        $data = $request->validate(['text' => 'required|string|max:2000']);
        $rules = AutoReply::where('domain', $domain)->where('user', $user)
            ->where('active', true)->orderBy('priority')->orderBy('id')->get();
        $matches = array_map(
            fn($m) => ['rule_id' => $m['rule']->id, 'name' => $m['rule']->name, 'keyword' => $m['keyword']],
            $svc->findMatches($rules, $data['text'])
        );
        return response()->json(['matches' => $matches]);
    }

    /**
     * POST /api/auto-replies/{autoReply}/fire { to } — VERIFY-ONLY dry run.
     * Never sends; reports the resolved payload + opt-out status.
     */
    public function fire(Request $request, AutoReply $autoReply, DynalinkService $dynalink)
    {
        $this->requireAdmin($request);
        $sess = $this->actor($request);
        abort_unless($autoReply->domain === $sess['domain'], 403);
        $data = $request->validate(['to' => 'required|string']);
        $to = preg_replace('/\D/', '', $data['to']);

        $from = $autoReply->from_number;
        if (!$from) {
            $nums = $dynalink->smsNumbers($this->dtoken(request()), $sess['domain'], $sess['user']);
            $from = (string) ($nums[0]['number'] ?? '');
        }

        // Verify-only: never sends. Reports exactly what a live fire would do.
        $resolved = app(\App\Services\CompanySettingsService::class)->resolve($sess['domain'], $autoReply->message);
        $blocked = app(\App\Services\OptOutService::class)->isOptedOut($sess['domain'], $to, $from !== '' ? $from : null);
        AutoReplyLog::create([
            'auto_reply_id' => $autoReply->id, 'domain' => $sess['domain'], 'user' => $sess['user'],
            'from_number' => $to, 'matched_keyword' => '(verify only — not sent)',
            'status' => 'verified',
            'detail' => $blocked ? 'Would be BLOCKED (opted out).' : 'Payload valid — a live trigger would send.',
        ]);
        DataChanged::send($sess['domain'], $sess['user'], 'auto-replies', 'saved', $autoReply->id);
        return response()->json([
            'verified' => true, 'to' => $to, 'from' => $from,
            'message' => $resolved, 'opted_out' => $blocked,
        ]);
    }

    /** GET /api/webhook-events?limit= — recent raw inbound webhook events. */
    public function events(Request $request)
    {
        [$domain] = $this->scope($request);
        return response()->json(
            \App\Models\WebhookEvent::where('domain', $domain)->orderByDesc('id')
                ->limit(min((int) $request->input('limit', 30), 100))->get()
        );
    }

    /** POST /api/auto-replies/{autoReply}/unlock { password } — default-action edit gate. */
    public function unlock(Request $request, AutoReply $autoReply)
    {
        [$domain] = $this->scope($request);
        abort_unless($autoReply->domain === $domain, 403);
        $data = $request->validate(['password' => 'required|string|max:120']);
        if (!$autoReply->is_default || strcasecmp(trim($data['password']), $domain) !== 0) {
            return response()->json(['message' => 'Incorrect password.'], 403);
        }
        return response()->json(['ok' => true]);
    }

    /** POST /api/auto-replies/{autoReply}/reset { password } — restore default body + keywords. */
    public function reset(Request $request, AutoReply $autoReply)
    {
        $this->requireAdmin($request);
        [$domain, $user] = $this->scope($request);
        abort_unless($autoReply->domain === $domain, 403);
        abort_unless($autoReply->is_default, 403, 'Only default actions can be reset.');
        $data = $request->validate(['password' => 'required|string|max:120']);
        if (strcasecmp(trim($data['password']), $domain) !== 0) {
            return response()->json(['message' => 'Incorrect password.'], 403);
        }
        $autoReply->update([
            'message' => $autoReply->default_body ?? $autoReply->message,
            'keywords' => $autoReply->default_keywords ?? $autoReply->keywords,
            'match_mode' => 'exact',
        ]);
        $this->audit($request, 'autoreply.reset', ['autoreply_id' => $autoReply->id, 'name' => $autoReply->name]);
        DataChanged::send($domain, $user, 'auto-replies', 'saved', $autoReply->id);
        return response()->json($autoReply->fresh());
    }

    /**
     * POST /api/auto-replies/reorder { ids: [...] } — admin only.
     * The array order becomes the evaluation order (lower priority = checked
     * first); only ids belonging to this tenant are renumbered.
     */
    public function reorder(Request $request)
    {
        [$domain, $user] = $this->scope($request);
        $this->requireAdmin($request);
        $data = $request->validate(['ids' => 'required|array|max:500', 'ids.*' => 'integer']);

        $ids = array_values(array_unique(array_map('intval', $data['ids'])));
        $mine = AutoReply::where('domain', $domain)->where('user', $user)
            ->whereIn('id', $ids)->pluck('id')->map('intval')->all();

        // Keep any rule the client didn't send (newly created elsewhere) at the end.
        $ordered = array_values(array_filter($ids, fn($id) => in_array($id, $mine, true)));
        foreach ($mine as $id) {
            if (!in_array($id, $ordered, true)) $ordered[] = $id;
        }

        foreach ($ordered as $i => $id) {
            AutoReply::where('id', $id)->where('domain', $domain)->where('user', $user)
                ->update(['priority' => $i]);
        }

        DataChanged::send($domain, $user, 'auto-replies', 'saved');
        return response()->json(['ok' => true, 'ids' => $ordered]);
    }

    /** Catch-all rules are always on: no keywords, no time window, 24/7. */
    protected function normalizeCatchAll(array $data): array
    {
        $isAny = array_key_exists('match_type', $data) && $data['match_type'] === AutoReply::MATCH_ANY;
        if (!$isAny) return $data;
        $data['keywords']    = [];
        $data['match_mode']  = 'any';
        $data['active_from'] = null;
        $data['active_to']   = null;
        $data['active_days'] = null;
        $data['schedule']    = null;
        $data['timezone']    = null;
        return $data;
    }

    /**
     * Fill in `numbers` for rules created before number assignment existed, so
     * the UI always shows a concrete selection (and the first save locks it in).
     */
    protected function decorate(iterable $rows, string $domain, string $user): array
    {
        $main  = $this->mainDigits($domain, $user);
        $cache = [];
        $out   = [];
        foreach ($rows as $r) {
            $r->numbers = $r->scopeNumbers() ?? $this->legacyScope($r, $main, $cache);
            $out[] = $r;
        }
        return $out;
    }

    /** Pre-feature scope: defaults everywhere, admin rules on main, agent rules on their own lines. */
    protected function legacyScope(AutoReply $r, string $main, array &$cache): array
    {
        if ($r->is_default) return [AutoReply::ALL_NUMBERS];
        $cb = (string) ($r->created_by ?? '');
        if (str_starts_with($cb, 'agent:')) {
            $aid = (int) substr($cb, 6);
            if (!array_key_exists($aid, $cache)) {
                $nums = [];
                try { if ($ag = \App\Models\Agent::find($aid)) $nums = $ag->assignedNumbers(); } catch (\Throwable $e) {}
                $cache[$aid] = array_values(array_filter(array_map(
                    fn($n) => preg_replace('/\D/', '', (string) $n), (array) $nums
                ), fn($n) => $n !== ''));
            }
            $mine = array_values(array_filter($cache[$aid], fn($n) => $n !== $main));
            return $mine === [] ? [AutoReply::ALL_NUMBERS] : $mine;
        }
        return $main !== '' ? [$main] : [AutoReply::ALL_NUMBERS];
    }

    protected function mainDigits(string $domain, string $user): string
    {
        try {
            return preg_replace('/\D/', '', (string) (\App\Models\Tenant::where('domain', $domain)
                ->where('dynalink_user', $user)->value('main_number') ?? ''));
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** Normalize numbers / days / window from a request payload. */
    protected function normalizeScope(array $data): array
    {
        if (array_key_exists('numbers', $data)) $data['numbers'] = $this->normalizeNumbers($data['numbers']);
        if (array_key_exists('schedule', $data)) {
            // A per-day schedule supersedes the legacy single from/to window.
            $data['schedule'] = $this->normalizeSchedule($data['schedule'] ?? null);
            $data['active_from'] = null;
            $data['active_to']   = null;
            $data['active_days'] = null;
        }
        if (array_key_exists('active_days', $data)) {
            $days = is_array($data['active_days']) ? $data['active_days'] : [];
            $days = array_values(array_unique(array_map('intval', $days)));
            sort($days);
            $data['active_days'] = $days === [] || count($days) === 7 ? null : $days;
        }
        foreach (['active_from', 'active_to'] as $k) {
            if (array_key_exists($k, $data)) $data[$k] = $data[$k] ? substr((string) $data[$k], 0, 5) : null;
        }
        if (array_key_exists('timezone', $data)) $data['timezone'] = $data['timezone'] ?: null;
        return $data;
    }

    /**
     * Normalize a per-day schedule: { "0": {"from":"09:00","to":"17:00"}, ... }
     * keyed 0=Sunday … 6=Saturday. Days that are absent mean "silent that day";
     * an empty result means 24/7.
     */
    protected function normalizeSchedule($v): ?array
    {
        if ($v === null || $v === '') return null;
        if (is_string($v)) {
            $decoded = json_decode($v, true);
            $v = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($v)) return null;

        $out = [];
        foreach ($v as $day => $w) {
            if (!is_numeric($day)) continue;
            $d = (int) $day;
            if ($d < 0 || $d > 6 || !is_array($w)) continue;
            $from = trim((string) ($w['from'] ?? ''));
            $to   = trim((string) ($w['to'] ?? ''));
            if (!preg_match('/^\d{2}:\d{2}$/', $from) || !preg_match('/^\d{2}:\d{2}$/', $to)) continue;
            $out[$d] = ['from' => $from, 'to' => $to];
        }
        ksort($out);
        return $out === [] ? null : $out;
    }

    /** Digits-only number list. '*' (or an empty list) means every number. */
    protected function normalizeNumbers($v): array
    {
        if ($v === null || $v === '') return [AutoReply::ALL_NUMBERS];
        $out = [];
        foreach ((is_array($v) ? $v : [$v]) as $n) {
            $s = trim((string) $n);
            if ($s === AutoReply::ALL_NUMBERS) return [AutoReply::ALL_NUMBERS];
            $d = preg_replace('/\D/', '', $s);
            if ($d !== '') $out[] = $d;
        }
        $out = array_values(array_unique($out));
        return $out === [] ? [AutoReply::ALL_NUMBERS] : $out;
    }

    /**
     * Agents may only run rules on their own assigned numbers (never the main
     * line). '*' narrows to their numbers instead of granting every line.
     * @return array|string|null  new numbers | 422 message | no change
     */
    protected function applyAgentScope(?array $numbers, array $ctx): array|string|null
    {
        if ($numbers === null) return null;
        [$agent, , $main] = $ctx;
        $allowed = array_values(array_filter(array_map(
            fn($n) => preg_replace('/\D/', '', (string) $n), (array) $agent->assignedNumbers()
        ), fn($n) => $n !== '' && $n !== $main));

        if (in_array(AutoReply::ALL_NUMBERS, $numbers, true)) {
            return $allowed === [] ? 'You have no assigned numbers yet.' : $allowed;
        }
        foreach ($numbers as $n) {
            if (!in_array($n, $allowed, true)) {
                return 'Agent rules may only run on your assigned numbers (not the main line).';
            }
        }
        return null;
    }

    protected function normalizeKeywords($v): array
    {
        if (is_string($v)) $v = explode(',');
        if (!is_array($v)) return [];
        return array_values(array_filter(array_map(fn($k) => trim((string) $k), $v), fn($k) => $k !== ''));
    }
}
