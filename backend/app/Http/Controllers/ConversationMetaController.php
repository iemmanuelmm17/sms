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
        try {
            return response()->json(
                ConversationMeta::where('domain', $domain)->get()
                    ->mapWithKeys(fn($m) => [$m->session_id => $this->shape($m)])
            );
        } catch (QueryException $e) {
            return $this->migrateHint();
        }
    }

    /** PUT /api/conversation-meta/{sessionId} — partial upsert. */
    public function upsert(Request $request, string $sessionId)
    {
        [$domain, $user] = $this->scope($request);
        $data = $request->validate([
            'agent_id'    => 'sometimes|nullable|integer|exists:agents,id',
            'identity_id' => 'sometimes|nullable|integer|exists:agent_identities,id',
            'pinned'      => 'sometimes|boolean',
            'status'      => 'sometimes|in:active,archived,spam,deleted,queued',
            'important'   => 'sometimes|boolean',
        ]);
        $actor = $this->actor($request);

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
