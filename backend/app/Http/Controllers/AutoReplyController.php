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
            ->orderByDesc('is_default')->orderBy('name');
        if ($ctx = $this->agentRuleContext($request, $domain, $user)) {
            return response()->json($q->get()->filter(fn($r) => $this->ruleVisibleToAgent($r, $ctx[1]))->values());
        }
        return response()->json($q->get());
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
            'keywords'    => 'required',
            'match_mode'  => 'sometimes|in:any,all,exact',
            'message'     => 'required|string|max:2000',
            'from_number' => 'sometimes|nullable|string|max:30',
            'active'      => 'sometimes|boolean',
        ]);
        $data['keywords'] = $this->normalizeKeywords($data['keywords']);
        if (empty($data['keywords'])) {
            return response()->json(['message' => 'At least one keyword is required.'], 422);
        }
        if ($ctx) {
            [$agent, , $main] = $ctx;
            $fromDigits = preg_replace('/\D/', '', (string) ($data['from_number'] ?? ''));
            $ok = $fromDigits === '' || ($fromDigits !== $main && in_array($fromDigits, $agent->assignedNumbers(), true));
            if (!$ok) return response()->json(['message' => 'Agent rules may only send from your assigned numbers (not the main line).'], 422);
            $data['from_number'] = $fromDigits !== '' ? $fromDigits : null;
        }
        [$cb, $cbName] = $ctx
            ? ['agent:' . $ctx[0]->id, trim($ctx[0]->first_name . ' ' . $ctx[0]->last_name)]
            : [$user, $request->session()->get('dynalink.display_name')];
        $rule = AutoReply::create($data + [
            'domain' => $domain, 'user' => $user, 'match_mode' => $data['match_mode'] ?? 'any',
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
        return response()->json($autoReply);
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
            'keywords'    => 'sometimes',
            'match_mode'  => 'sometimes|in:any,all,exact',
            'message'     => 'sometimes|string|max:2000',
            'from_number' => 'sometimes|nullable|string|max:30',
            'active'      => 'sometimes|boolean',
        ]);
        if (isset($data['keywords'])) {
            $data['keywords'] = $this->normalizeKeywords($data['keywords']);
            if (empty($data['keywords'])) {
                return response()->json(['message' => 'At least one keyword is required.'], 422);
            }
        }
        if ($ctx && array_key_exists('from_number', $data)) {
            [$agent, , $main] = $ctx;
            $fromDigits = preg_replace('/\D/', '', (string) ($data['from_number'] ?? ''));
            $ok = $fromDigits === '' || ($fromDigits !== $main && in_array($fromDigits, $agent->assignedNumbers(), true));
            if (!$ok) return response()->json(['message' => 'Agent rules may only send from your assigned numbers (not the main line).'], 422);
            $data['from_number'] = $fromDigits !== '' ? $fromDigits : null;
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
            ->where('active', true)->orderBy('id')->get();
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

    protected function normalizeKeywords($v): array
    {
        if (is_string($v)) $v = explode(',');
        if (!is_array($v)) return [];
        return array_values(array_filter(array_map(fn($k) => trim((string) $k), $v), fn($k) => $k !== ''));
    }
}
