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
    protected function scope(Request $r): array
    {
        $a = $this->actor($r);
        return [$a['domain'], $a['user']];
    }

    /** GET /api/conversation-meta */
    public function index(Request $request)
    {
        [$domain, $user] = $this->scope($request);
        try {
            return response()->json(
                ConversationMeta::where('domain', $domain)->where('user', $user)->get()
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
            'agent_id'  => 'sometimes|nullable|integer|exists:agents,id',
            'pinned'    => 'sometimes|boolean',
            'status'    => 'sometimes|in:active,archived,spam,deleted,queued',
            'important' => 'sometimes|boolean',
        ]);
        $actor = $this->actor($request);
        if ($actor['role'] === 'agent' && array_key_exists('agent_id', $data)
            && $data['agent_id'] !== null && (int) $data['agent_id'] !== (int) $actor['agent_id']) {
            abort(403, 'Agents can only assign conversations to themselves.');
        }
        $prevAgent = null;
        if (array_key_exists('agent_id', $data)) {
            $prevAgent = ConversationMeta::where('domain', $domain)->where('user', $user)
                ->where('session_id', $sessionId)->value('agent_id');
        }
        try {
            $m = ConversationMeta::updateOrCreate(
                ['domain' => $domain, 'user' => $user, 'session_id' => $sessionId],
                $data
            );
        } catch (QueryException $e) {
            return $this->migrateHint();
        }
        if (array_key_exists('agent_id', $data) && (string) ($prevAgent ?? '') !== (string) ($m->agent_id ?? '')) {
            $this->audit($request, 'conversation.assigned', ['session_id' => $sessionId, 'from' => $prevAgent, 'to' => $m->agent_id]);
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
            'agent_id'  => $m->agent_id,
            'pinned'    => (bool) $m->pinned,
            'status'    => $m->status ?? 'active',
            'important' => (bool) $m->important,
            'updated_at' => $m->updated_at?->toDateTimeString(),
        ];
    }
}
