<?php

namespace App\Http\Controllers;

use App\Events\DataChanged;
use App\Models\KeywordAlert;
use App\Models\KeywordAlertLog;
use App\Services\AutoReplyService;
use App\Http\Controllers\Concerns\ResolvesActor;
use Illuminate\Http\Request;

/**
 * Keyword Alerts — the admin-only watchlist ("notify, never reply").
 *
 * Rules live in the same tenant-wide partition as auto-replies, so the
 * webhook (which resolves the event's user on its own) always finds them.
 * Agents are rejected at the door: requireAdmin() runs inside scope().
 */
class KeywordAlertController extends Controller
{
    use ResolvesActor;

    /** Admin-only + the ONE domain-wide rule partition. */
    protected function scope(Request $r): array
    {
        $this->requireAdmin($r);
        $a = $this->actor($r);
        return [$a['domain'], AutoReplyService::rulePartitionUser($a['domain'], (string) $a['user'])];
    }

    /** Accepts an array or a comma/newline/semicolon separated string. */
    protected function normalizeKeywords(mixed $raw): array
    {
        $parts = is_array($raw) ? $raw : (preg_split('/[\n\r,;]+/', (string) $raw) ?: []);
        $out = [];
        $seen = [];
        foreach ($parts as $p) {
            $k = mb_substr(trim((string) $p), 0, 64);
            $low = mb_strtolower($k);
            if ($k === '' || isset($seen[$low])) continue;
            $seen[$low] = true;
            $out[] = $k;
            if (count($out) >= 50) break;
        }
        return $out;
    }

    /** Digits-only, de-duped; null = "every tenant number". */
    protected function normalizeNumbers(mixed $raw): ?array
    {
        if ($raw === null || $raw === '' || $raw === []) return null;
        $nums = array_values(array_filter(array_map(
            fn($n) => preg_replace('/\D/', '', (string) $n),
            (array) $raw
        ), fn($n) => $n !== ''));
        return $nums !== [] ? array_slice(array_values(array_unique($nums)), 0, 100) : null;
    }

    protected function validateRule(Request $request): array
    {
        $data = $request->validate([
            'name'       => 'required|string|max:120',
            'keywords'   => 'required',
            'match_mode' => 'sometimes|in:any,all,exact',
            'direction'  => 'sometimes|in:in,out,both',
            'numbers'    => 'sometimes|nullable|array|max:100',
            'numbers.*'  => 'string|max:32',
            'active'     => 'sometimes|boolean',
        ]);
        $data['keywords'] = $this->normalizeKeywords($data['keywords']);
        if (empty($data['keywords'])) {
            abort(response()->json(['message' => 'At least one keyword is required.'], 422));
        }
        $data['match_mode'] = $data['match_mode'] ?? 'any';
        $data['direction']  = $data['direction'] ?? 'both';
        $data['numbers']    = $this->normalizeNumbers($data['numbers'] ?? null);
        if (array_key_exists('active', $data)) $data['active'] = (bool) $data['active'];
        return $data;
    }

    /** GET /api/keyword-alerts */
    public function index(Request $request)
    {
        [$domain, $user] = $this->scope($request);
        return response()->json(KeywordAlert::where('domain', $domain)->where('user', $user)
            ->orderByDesc('id')->get());
    }

    /** POST /api/keyword-alerts */
    public function store(Request $request)
    {
        [$domain, $user] = $this->scope($request);
        $data = $this->validateRule($request);
        $byName = $request->session()->get('dynalink.display_name');
        $rule = KeywordAlert::create($data + [
            'domain' => $domain, 'user' => $user, 'active' => true,
            'created_by' => (string) $user, 'created_by_name' => $byName,
            'updated_by' => (string) $user, 'updated_by_name' => $byName,
        ]);
        $this->audit($request, 'keywordalert.created', ['keyword_alert_id' => $rule->id, 'name' => $rule->name]);
        DataChanged::send($domain, $user, 'keyword-alerts', 'saved', $rule->id);
        return response()->json($rule, 201);
    }

    /** PUT /api/keyword-alerts/{keywordAlert} */
    public function update(Request $request, KeywordAlert $keywordAlert)
    {
        [$domain, $user] = $this->scope($request);
        abort_unless($keywordAlert->domain === $domain, 403);
        $data = $this->validateRule($request);
        $keywordAlert->update($data + [
            'updated_by' => (string) $user,
            'updated_by_name' => $request->session()->get('dynalink.display_name'),
        ]);
        $this->audit($request, 'keywordalert.updated', ['keyword_alert_id' => $keywordAlert->id, 'name' => $keywordAlert->name]);
        DataChanged::send($domain, $user, 'keyword-alerts', 'saved', $keywordAlert->id);
        return response()->json($keywordAlert);
    }

    /** DELETE /api/keyword-alerts/{keywordAlert} — logs stay (they snapshot rule_name). */
    public function destroy(Request $request, KeywordAlert $keywordAlert)
    {
        [$domain, $user] = $this->scope($request);
        abort_unless($keywordAlert->domain === $domain, 403);
        $this->audit($request, 'keywordalert.deleted', ['keyword_alert_id' => $keywordAlert->id, 'name' => $keywordAlert->name]);
        $keywordAlert->delete();
        DataChanged::send($domain, $user, 'keyword-alerts', 'deleted', $keywordAlert->id);
        return response()->json(['ok' => true]);
    }

    /** GET /api/keyword-alert-logs?limit&rule_id&unread_only — feed + unread count. */
    public function logs(Request $request)
    {
        [$domain, $user] = $this->scope($request);
        $unread = KeywordAlertLog::where('domain', $domain)->where('user', $user)
            ->whereNull('read_at')->count();
        $q = KeywordAlertLog::where('domain', $domain)->where('user', $user)
            ->orderByDesc('id');
        if ($request->filled('rule_id')) $q->where('keyword_alert_id', $request->input('rule_id'));
        if ($request->boolean('unread_only')) $q->whereNull('read_at');
        return response()->json([
            'unread' => $unread,
            'items'  => $q->limit(min((int) $request->input('limit', 100), 300))->get(),
        ]);
    }

    /** POST /api/keyword-alert-logs/{log}/read */
    public function read(Request $request, KeywordAlertLog $log)
    {
        [$domain, $user] = $this->scope($request);
        abort_unless($log->domain === $domain, 403);
        if (!$log->read_at) $log->update(['read_at' => now()]);
        DataChanged::send($domain, $user, 'keyword-alerts', 'read', $log->id);
        return response()->json(['ok' => true]);
    }

    /** POST /api/keyword-alert-logs/read-all */
    public function readAll(Request $request)
    {
        [$domain, $user] = $this->scope($request);
        KeywordAlertLog::where('domain', $domain)->where('user', $user)
            ->whereNull('read_at')->update(['read_at' => now()]);
        DataChanged::send($domain, $user, 'keyword-alerts', 'read-all');
        return response()->json(['ok' => true]);
    }
}
