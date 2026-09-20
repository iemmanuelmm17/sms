<?php

namespace App\Http\Controllers;

use App\Events\DataChanged;
use App\Http\Controllers\Concerns\ResolvesActor;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\PasswordHistory;
use App\Rules\PasswordPolicy;
use App\Services\PasswordPolicyService;
use App\Models\ConversationMeta;
use App\Models\PasswordResetRequest;
use App\Models\ScheduledMessage;
use Illuminate\Support\Facades\DB;
use App\Services\DynalinkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/** Admin-only agent account management. Credentials are write-only. */
class AgentController extends Controller
{
    use ResolvesActor;

    public function __construct(protected DynalinkService $dynalink) {}

    protected function adminScope(Request $r): array
    {
        $a = $this->actor($r);
        $this->requireAdmin($a);
        return [$a['domain'], $a['user']];
    }

    protected function owned(Request $r, Agent $agent): array
    {
        [$domain, $user] = $this->adminScope($r);
        abort_unless($agent->domain === $domain && $agent->user === $user, 403);
        return [$domain, $user];
    }

    /** GET /api/agents */
    /** GET /api/agents/directory — lightweight active-agent list for inbox display (both roles). */
    public function directory(Request $request)
    {
        $actor = $this->actor($request);
        $list = \Illuminate\Support\Facades\Cache::remember(Agent::listKey($actor['domain'], $actor['user']) . ':dir', 120, function () use ($actor) {
            return Agent::where('domain', $actor['domain'])->where('user', $actor['user'])
                ->where('status', 'active')->orderBy('first_name')->orderBy('last_name')->get()
                ->map(fn($a) => ['id' => $a->id, 'first_name' => $a->first_name,
                    'last_name' => $a->last_name, 'tag_color' => $a->tag_color,
                    'numbers' => $a->assignedNumbers()])->values()->toArray();
        });
        return response()->json($list);
    }

    public function index(Request $request)
    {
        [$domain, $user] = $this->adminScope($request);
        $list = \Illuminate\Support\Facades\Cache::remember(Agent::listKey($domain, $user), 120, function () use ($domain, $user) {
            $list = Agent::where('domain', $domain)->where('user', $user)
                ->orderBy('first_name')->orderBy('last_name')->get()->toArray();
            // Admin roster rows (display-only; created with the tenant, can't be edited/deleted).
            try {
                $tenant = \App\Models\Tenant::where('domain', $domain)->where('dynalink_user', $user)->first();
                $admins = $tenant ? \App\Models\TenantAdmin::where('tenant_id', $tenant->id)->orderBy('username')->get() : collect();
                foreach ($admins as $ad) {
                    $list[] = ['id' => 'admin-' . $ad->id, 'is_admin' => true,
                        'username' => $ad->username, 'first_name' => $ad->first_name, 'last_name' => $ad->last_name,
                        'domain' => $domain, 'status' => $ad->status ?? 'active', 'tag_color' => '#334155'];
                }
            } catch (\Throwable $e) {}
            return $list;
        });
        return response()->json($list);
    }

    /** POST /api/agents — username is [a-z0-9]+, case-insensitive unique. */
    public function store(Request $request)
    {
        [$domain, $user] = $this->adminScope($request);
        $data = $request->validate([
            'first_name'      => 'required|string|max:60',
            'last_name'       => 'required|string|max:60',
            'tag_color'       => 'required|regex:/^#[0-9a-fA-F]{6}$/',
            'username'        => ['required', 'string', 'max:60', 'regex:/^[a-z0-9]+$/i',
                Rule::unique('agents', 'username')->where('domain', $domain)],
            'password'        => 'required|string|min:8|max:200',
            'secret_question' => 'required|string|max:200',
            'secret_answer'   => 'required|string|max:200',
            'default_number'  => 'nullable|string|max:30',
            'allowed_numbers' => 'nullable|array|max:20',
            'allowed_numbers.*' => 'nullable|string|max:30',
        ]);
        $username = mb_strtolower($data['username']);
        if (Agent::where('domain', $domain)->whereRaw('LOWER(username) = ?', [$username])->exists()) {
            return response()->json(['message' => 'That username is taken.'], 422);
        }
        $this->assertNumberAllowed($request, $domain, $user, $data['default_number'] ?? null);
        $allowed = [];
        foreach ((array) ($data['allowed_numbers'] ?? []) as $n) {
            $this->assertNumberAllowed($request, $domain, $user, $n, 'Allowed number');
            $d = preg_replace('/\D/', '', (string) $n);
            if ($d !== '' && !in_array($d, $allowed, true)) $allowed[] = $d;
        }
        $defDigits = preg_replace('/\D/', '', (string) ($data['default_number'] ?? ''));
        $this->assertMainIncluded($this->tenantMain($request, $domain, $user),
            $defDigits !== '' ? $defDigits : null, $allowed);
        $agent = Agent::create([
            'domain' => $domain, 'user' => $user,
            'first_name' => $data['first_name'], 'last_name' => $data['last_name'],
            'tag_color' => $data['tag_color'], 'username' => $username,
            'password_hash' => Hash::make($data['password']),
            'secret_question' => trim($data['secret_question']),
            'secret_answer_hash' => Hash::make(self::normalizeAnswer($data['secret_answer'])),
            'default_number' => isset($data['default_number']) ? preg_replace('/\D/', '', $data['default_number']) : null,
            'allowed_numbers' => $allowed,
            'status' => 'active',
        ]);
        // A brand-new account's first password starts its expiry cycle too,
        // otherwise it would sit at NULL and never expire.
        PasswordPolicyService::startCycle($agent, PasswordHistory::TYPE_AGENT,
            PasswordPolicyService::T_ADMIN, [
                'domain'     => $domain,
                'actor_type' => 'admin',
                'actor_id'   => null,
                'actor_name' => $user . '@' . $domain,
                'ip'         => $request->ip(),
                'detail'     => ['on_create' => true],
            ]);
        AuditLog::record($domain, 'admin', null, $user . '@' . $domain,
            'agent.created', ['agent_id' => $agent->id, 'username' => $username], $request->ip());
        $obT = $request->session()->get('tenant'); // acting tenant admin earns agent_created
        \App\Services\OnboardingService::markAdminStep(['tenant_admin_id' => $obT['id'] ?? null], 'agent_created');
        Agent::bustList($domain, $user);
        DataChanged::send($domain, $user, 'agents', 'saved', $agent->id);
        return response()->json($agent->fresh(), 201);
    }

    /** PUT /api/agents/{agent} — usernames are immutable; status flips active/deactivated. */
    public function update(Request $request, Agent $agent)
    {
        [$domain, $user] = $this->owned($request, $agent);
        $data = $request->validate([
            'first_name'      => 'sometimes|required|string|max:60',
            'last_name'       => 'sometimes|required|string|max:60',
            'tag_color'       => 'sometimes|required|regex:/^#[0-9a-fA-F]{6}$/',
            'secret_question' => 'sometimes|required|string|max:200',
            'secret_answer'   => 'sometimes|required|string|max:200',
            'default_number'  => 'sometimes|nullable|string|max:30',
            'allowed_numbers' => 'sometimes|nullable|array|max:20',
            'allowed_numbers.*' => 'nullable|string|max:30',
            'status'          => 'sometimes|in:active,deactivated',
        ]);
        if (isset($data['secret_answer'])) {
            $data['secret_answer_hash'] = Hash::make(self::normalizeAnswer($data['secret_answer']));
            unset($data['secret_answer']);
        }
        if (array_key_exists('default_number', $data)) {
            $this->assertNumberAllowed($request, $domain, $user, $data['default_number']);
            $data['default_number'] = $data['default_number']
                ? preg_replace('/\D/', '', $data['default_number']) : null;
        }
        if (array_key_exists('allowed_numbers', $data)) {
            $allowed = [];
            foreach ((array) ($data['allowed_numbers'] ?? []) as $n) {
                $this->assertNumberAllowed($request, $domain, $user, $n, 'Allowed number');
                $d = preg_replace('/\D/', '', (string) $n);
                if ($d !== '' && !in_array($d, $allowed, true)) $allowed[] = $d;
            }
            $data['allowed_numbers'] = $allowed;
        }
        if (array_key_exists('default_number', $data) || array_key_exists('allowed_numbers', $data)) {
            $effDefault = array_key_exists('default_number', $data)
                ? ($data['default_number'] ?: null)
                : ($agent->default_number ?: null);
            $effAllowed = array_key_exists('allowed_numbers', $data)
                ? (array) $data['allowed_numbers']
                : (array) ($agent->allowed_numbers ?? []);
            $this->assertMainIncluded($this->tenantMain($request, $domain, $user), $effDefault, $effAllowed);
        }
        if (isset($data['status']) && $data['status'] !== $agent->status) {
            $data['session_version'] = $agent->session_version + 1; // kick sessions
        }
        $numBefore = [
            'allowed_numbers' => array_values((array) ($agent->allowed_numbers ?? [])),
            'default_number' => $agent->default_number ?: null,
        ];
        $agent->update($data);
        $numChg = [];
        foreach (['allowed_numbers', 'default_number'] as $nk) {
            if (!array_key_exists($nk, $data)) continue;
            $to = $nk === 'allowed_numbers' ? array_values((array) $data[$nk]) : ($data[$nk] ?: null);
            $was = $numBefore[$nk];
            if ($nk === 'allowed_numbers') { $c1 = $to; $c2 = $was; sort($c1); sort($c2); if ($c1 === $c2) continue; }
            elseif ($to === $was) continue;
            $numChg[$nk] = ['from' => $was, 'to' => $to];
        }
        AuditLog::record($domain, 'admin', null, $user . '@' . $domain,
            'agent.updated', ['agent_id' => $agent->id, 'keys' => array_keys($data)]
                + ($numChg === [] ? [] : ['number_changes' => $numChg]), $request->ip());
        Agent::bustList($domain, $user);
        DataChanged::send($domain, $user, 'agents', 'saved', $agent->id);
        return response()->json($agent->fresh());
    }

    /**
     * POST /api/agents/{agent}/password — Admin sets a new password directly,
     * gated by the ADMIN's own password (re-auth, verified against Dynalink).
     */
    public function setPassword(Request $request, Agent $agent)
    {
        [$domain, $user] = $this->owned($request, $agent);
        $data = $request->validate([
            'admin_password' => 'required|string',
            'new_password'   => ['required', 'string', new PasswordPolicy()],
        ]);
        try {
            $this->dynalink->login($user . '@' . $domain, $data['admin_password']);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Incorrect admin password.'], 403);
        }
        if ($err = PasswordPolicyService::reuseError($agent, PasswordHistory::TYPE_AGENT, $data['new_password'])) {
            return response()->json(['message' => $err], 422);
        }
        PasswordPolicyService::change($agent, PasswordHistory::TYPE_AGENT, $data['new_password'],
            PasswordPolicyService::T_ADMIN, [
                'domain'     => $domain,
                'actor_type' => 'admin',
                'actor_id'   => null,
                'actor_name' => $user . '@' . $domain,
                'ip'         => $request->ip(),
                'detail'     => ['agent_id' => $agent->id],
            ]);
        Agent::bustList($domain, $user);
        DataChanged::send($domain, $user, 'agents', 'saved', $agent->id);
        return response()->json(['ok' => true]);
    }

    /** DELETE is retired — Admins deactivate instead (assignments are kept). */
    /** GET /api/agents/{agent}/delete-preview — impact counts for the 2-step modal. */
    public function deletePreview(Request $request, Agent $agent)
    {
        [$domain, $user] = $this->owned($request, $agent);
        return response()->json([
            'agent' => ['id' => $agent->id, 'username' => $agent->username,
                'display_name' => trim($agent->first_name . ' ' . $agent->last_name)],
            'counts' => [
                'assigned_conversations' => ConversationMeta::where('agent_id', $agent->id)->count(),
                'pending_scheduled' => ScheduledMessage::where('created_by', 'agent:' . $agent->id)
                    ->whereIn('status', ['pending', 'sending'])->count(),
                'reset_requests' => PasswordResetRequest::where('agent_id', $agent->id)->count(),
            ],
        ]);
    }

    /**
     * DELETE /api/agents/{agent} { confirm_username, admin_password } — 2-step delete.
     * Conversations are unassigned (pins/status kept), the agent's pending
     * scheduled messages are cancelled, reset tokens die with the account.
     */
    public function destroy(Request $request, Agent $agent)
    {
        [$domain, $user] = $this->owned($request, $agent);
        $data = $request->validate([
            'confirm_username' => 'required|string',
            'admin_password' => 'required|string',
        ]);
        // Agents created without a login have no username — there is nothing to
        // type, so the confirmation falls back to their full name.
        $expected = trim((string) $agent->username);
        if ($expected === '') $expected = trim((string) $agent->first_name . ' ' . (string) $agent->last_name);
        if ($expected !== '' && mb_strtolower(trim($data['confirm_username'])) !== mb_strtolower($expected)) {
            return response()->json(['message' => (string) $agent->username !== ''
                ? 'Typed username does not match this agent.'
                : "This agent has no login — type their full name ({$expected}) to confirm."], 422);
        }
        if (!$this->verifyAdminPassword($request, $data['admin_password'])) {
            return response()->json(['message' => 'Incorrect admin password.'], 403);
        }
        $counts = DB::transaction(function () use ($agent) {
            $unassigned = ConversationMeta::where('agent_id', $agent->id)->update(['agent_id' => null]);
            $cancelled = ScheduledMessage::where('created_by', 'agent:' . $agent->id)
                ->whereIn('status', ['pending', 'sending'])->update(['status' => 'cancelled']);
            $tokens = PasswordResetRequest::where('agent_id', $agent->id)->delete();
            $agent->delete();
            return ['conversations_unassigned' => $unassigned, 'scheduled_cancelled' => $cancelled,
                'reset_requests_deleted' => $tokens];
        });
        AuditLog::record($domain, 'admin', null, $user . '@' . $domain,
            'agent.deleted', ['agent_id' => $agent->id, 'username' => $agent->username] + $counts, $request->ip());
        Agent::bustList($domain, $user);
        DataChanged::send($domain, $user, 'agents', 'deleted', $agent->id);
        return response()->json(['ok' => true, 'counts' => $counts]);
    }

    public static function normalizeAnswer(string $s): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($s)));
    }

    /** Assigned numbers must be among the Admin's SMS numbers (fail-open when Dynalink is down). */
    /** Tenant main SMS number for this admin's scope (null = none/legacy). */
    protected function tenantMain(Request $r, string $domain, string $user): ?string
    {
        try {
            $a = $this->actor($r);
            if (!empty($a['tenant_id']) && ($t = \App\Models\Tenant::find($a['tenant_id']))) {
                return $t->main_number ?: null;
            }
        } catch (\Throwable $e) {
        }
        try {
            $t = \App\Models\Tenant::where('domain', $domain)->where('dynalink_user', $user)->first();
            return $t?->main_number ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** 422 unless the tenant main number (when set) is among the agent's numbers. */
    protected function assertMainIncluded(?string $main, ?string $default, array $allowed): void
    {
        if (!$main) return;
        $have = array_values(array_filter([$default, ...$allowed]));
        abort_unless(in_array($main, $have, true), response()->json(
            ['message' => 'The main SMS number (' . $main . ') must stay assigned to every agent.'], 422));
    }

    protected function assertNumberAllowed(Request $r, string $domain, string $user, ?string $number, string $label = 'Default number'): void
    {
        if (!$number) return;
        $digits = preg_replace('/\D/', '', $number);
        if (strlen($digits) < 10) {
            abort(response()->json(['message' => $label . ' needs at least 10 digits.'], 422));
        }
        try {
            $nums = $this->dynalink->smsNumbers($this->dtoken($r), $domain, $user);
            $mine = array_map(fn($n) => preg_replace('/\D/', '', (string) ($n['number'] ?? '')), $nums);
            abort_unless(in_array($digits, $mine, true), response()->json(
                ['message' => 'Choose one of your assigned SMS numbers.'], 422));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e; // our own 422s pass through
        } catch (\Throwable $e) {
            Log::warning('agent number check skipped: provider unreachable');
        }
    }
}
