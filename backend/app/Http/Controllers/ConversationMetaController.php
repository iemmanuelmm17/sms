<?php

namespace App\Http\Controllers;

use App\Events\DataChanged;
use App\Models\ConversationMeta;
use Illuminate\Database\QueryException;
use App\Http\Controllers\Concerns\ResolvesActor;
use Illuminate\Http\Request;

/**
 * Local per-conversation state: agent assignment, pins, importance,
 * and inbox status (active | archived | spam | deleted).
 * Keyed by Dynalink messagesession-id. index() returns a map:
 * { "<sessionId>": { "agent_id": 1|null, "pinned": bool, "status": str, "important": bool } }
 */
class ConversationMetaController extends Controller
{
    use ResolvesActor;
    /**
     * Conversation metadata is TENANT-WIDE, not per viewer.
     *
     * It used to be keyed on the viewing actor, which gave every user a private
     * copy — two agents on the same shared number could not see each other's
     * queue, and an admin saw neither. Reads and writes are now scoped by
     * domain alone; `user` is still written for provenance but is not part of
     * the identity (see migration 000049).
     */
    protected function scope(Request $r): array
    {
        $a = $this->actor($r);
        return [$a['domain'], $a['user']];
    }

    /** Find-or-make the single shared row for a conversation. */
    protected function rowFor(string $domain, string $user, string $sessionId): ConversationMeta
    {
        $m = ConversationMeta::where('domain', $domain)->where('session_id', $sessionId)->first();
        if ($m) return $m;
        return new ConversationMeta([
            'domain' => $domain, 'user' => $user, 'session_id' => $sessionId,
        ]);
    }

    /** GET /api/conversation-meta */
    public function index(Request $request)
    {
        [$domain, $user] = $this->scope($request);
        $actor = $this->actor($request);
        try {
            $rows = ConversationMeta::where('domain', $domain)->get();
        } catch (QueryException $e) {
            return $this->migrateHint();
        }
        // Agents only receive metadata for conversations on numbers they can
        // read (own or shared). Sessions whose number cannot be resolved
        // locally (inbound-only threads never sent from) stay included: their
        // ids are opaque provider ids an agent can only have obtained from a
        // conversation they ARE allowed to see, and filtering them out would
        // silently drop pins/claims on first-touch threads.
        if (($actor['role'] ?? '') === 'agent') {
            $readable = $this->agentReadable($request, $actor);
            $map = $this->sessionNumbers($domain, $rows->pluck('session_id')->all());
            $rows = $rows->filter(fn($m) => $this->sessionVisible($map[(string) $m->session_id] ?? [], $readable));
        }
        return response()->json($rows->mapWithKeys(fn($m) => [$m->session_id => $this->shape($m)]));
    }

    /** PUT /api/conversation-meta/{sessionId} — partial upsert. */
    public function upsert(Request $request, string $sessionId)
    {
        [$domain, $user] = $this->scope($request);
        // Tenant-scoped existence checks: a global exists:agents,id let an
        // admin reference another tenant's agent AND turned validation errors
        // into a cross-tenant id-existence oracle.
        $data = $request->validate([
            'agent_id'    => ['sometimes', 'nullable', 'integer',
                \Illuminate\Validation\Rule::exists('agents', 'id')->where('domain', $domain)],
            'identity_id' => ['sometimes', 'nullable', 'integer',
                \Illuminate\Validation\Rule::exists('agent_identities', 'id')->where('domain', $domain)],
            'pinned'      => 'sometimes|boolean',
            'status'      => 'sometimes|in:active,archived,spam,deleted,queued',
            'important'   => 'sometimes|boolean',
        ]);
        $actor = $this->actor($request);

        // Visibility gate: an agent may only set status/pinned/important (or
        // claim) conversations on numbers they can read. Unresolved sessions
        // pass — see index() for why that is the safe default.
        if (($actor['role'] ?? '') === 'agent') {
            $map = $this->sessionNumbers($domain, [$sessionId]);
            $nums = $map[(string) $sessionId] ?? [];
            if ($nums !== [] && !$this->sessionVisible($nums, $this->agentReadable($request, $actor))) {
                abort(response()->json(['message' => 'You can only manage conversations on your own or shared numbers.'], 403));
            }
        }

        // Portal users claim via identity_id (they have no legacy agents row).
        if (($actor['role'] ?? '') === 'agent' && !empty($actor['portal_auth'])) {
            if (array_key_exists('agent_id', $data) && $data['agent_id'] !== null) {
                abort(403, 'Agents can only assign conversations to themselves.');
            }
            if (array_key_exists('identity_id', $data) && $data['identity_id'] !== null
                && (int) $data['identity_id'] !== (int) ($actor['identity_id'] ?? 0)) {
                abort(403, 'Agents can only assign conversations to themselves.');
            }
            // Claiming as an identity clears any stale legacy assignment.
            if (array_key_exists('identity_id', $data)) $data['agent_id'] = null;
        } elseif (($actor['role'] ?? '') === 'agent') {
            if (array_key_exists('agent_id', $data)
                && $data['agent_id'] !== null && (int) $data['agent_id'] !== (int) $actor['agent_id']) {
                abort(403, 'Agents can only assign conversations to themselves.');
            }
        }
        $prevAgent = null;
        if (array_key_exists('identity_id', $data)) {
            $prevAgent = ConversationMeta::where('domain', $domain)
                ->where('session_id', $sessionId)->value('identity_id');
        }
        if (array_key_exists('agent_id', $data)) {
            $prevAgent = ConversationMeta::where('domain', $domain)
                ->where('session_id', $sessionId)->value('agent_id');
        }
        try {
            $m = $this->rowFor($domain, $user, $sessionId);
            $m->fill($data);
            $m->save();
        } catch (QueryException $e) {
            return $this->migrateHint();
        }
        $nowAssigned = $m->identity_id ?? $m->agent_id;
        if ((array_key_exists('agent_id', $data) || array_key_exists('identity_id', $data))
            && (string) ($prevAgent ?? '') !== (string) ($nowAssigned ?? '')) {
            $this->audit($request, 'conversation.assigned',
                ['session_id' => $sessionId, 'from' => $prevAgent, 'to' => $nowAssigned]);
        }
        DataChanged::send($domain, $user, 'convo-meta', 'saved', $sessionId, $this->shape($m));
        return response()->json($this->shape($m));
    }

    /**
     * Missing table/columns (migrations not run) → actionable 422
     * instead of a cryptic 500.
     */
    protected function migrateHint()
    {
        return response()->json([
            'message' => 'Conversation data unavailable — run "php artisan migrate" inside backend/ and retry.',
        ], 422);
    }

    /**
     * Local session → business-number map. Dynalink messagesession-ids are
     * opaque; SentMessageLog is the only server-side link we keep (it records
     * from_number + session_id for every send). Inbound-only sessions resolve
     * to nothing and are treated as "unresolved" by the callers.
     *
     * @return array<string, string[]>  sessionId => list of digit strings
     */
    protected function sessionNumbers(string $domain, array $sessionIds): array
    {
        $out = [];
        $ids = array_values(array_unique(array_filter(array_map('strval', $sessionIds))));
        if ($ids === []) return $out;
        try {
            $rows = \App\Models\SentMessageLog::where('domain', $domain)
                ->whereIn('session_id', $ids)
                ->whereNotNull('from_number')
                ->get(['session_id', 'from_number']);
            foreach ($rows as $r) {
                $d = preg_replace('/\D/', '', (string) $r->from_number);
                if ($d === '' || $r->session_id === null) continue;
                $out[(string) $r->session_id][$d] = true;
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('conversation-meta: session number map failed', ['error' => (string) $e]);
        }
        return array_map('array_keys', $out);
    }

    /** The agent's readable numbers — portal grants, or legacy assignment. */
    protected function agentReadable(Request $r, array $actor): array
    {
        try {
            if (!empty($actor['portal_auth'])) {
                $ext = $actor['ext'] ?? $actor['user'];
                return app(\App\Services\AgentAccess::class)
                    ->readableNumbers((string) $actor['domain'], (string) $ext, $this->dtoken($r));
            }
            $agent = \App\Models\Agent::find($actor['agent_id'] ?? null);
            return $agent ? $agent->assignedNumbers() : [];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('conversation-meta: readable numbers failed (fail closed)', ['error' => (string) $e]);
            return [];
        }
    }

    /** True when no numbers resolved, or any resolved number is readable (10/11-digit tolerant). */
    protected function sessionVisible(array $nums, array $readable): bool
    {
        if ($nums === []) return true;
        foreach ($nums as $n) {
            $cands = [(string) $n];
            if (strlen($n) === 11 && str_starts_with($n, '1')) $cands[] = substr($n, 1);
            if (strlen($n) === 10) $cands[] = '1' . $n;
            foreach ($cands as $c) { if (in_array($c, $readable, true)) return true; }
        }
        return false;
    }

    protected function shape(ConversationMeta $m): array
    {
        return [
            'agent_id'    => $m->agent_id,
            'identity_id' => $m->identity_id,
            'pinned'      => (bool) $m->pinned,
            'status'    => $m->status ?? 'active',
            'important' => (bool) $m->important,
            'updated_at' => $m->updated_at?->toDateTimeString(),
        ];
    }
}
