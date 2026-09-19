<?php

namespace App\Http\Controllers;

use App\Events\DataChanged;
use App\Jobs\SendScheduledMessage;
use App\Models\ScheduledMessage;
use App\Http\Controllers\Concerns\ResolvesActor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Scheduled SMS/MMS. Recipients can be individual contacts, a group,
 * a whole company, or CSV rows. Execution ALWAYS fans out to ONE
 * job/message per contact (SendScheduledMessage), never a single blast.
 *
 * CSV recipients carry per-row vars (col1/col2/col3) that are
 * substituted into the message per recipient ({col1}, {col2}, {col3},
 * plus {name} and {phone}).
 *
 * If the requested send time has already passed, the message is
 * clamped to NOW and sent right away (no validation error).
 */
class ScheduledMessageController extends Controller
{
    use ResolvesActor;
    protected function scope(Request $r): array
    {
        $a = $this->actor($r);
        return [$a['domain'], $a['user']];
    }

    /** GET /api/scheduled */
    /** GET /api/ops/health — queue worker heartbeat + past-due count. */
    public function opsHealth(Request $request)
    {
        [$domain, $user] = $this->scope($request);
        $actor = $this->actor($request);
        $seen = null;
        try { $seen = \Illuminate\Support\Facades\Cache::get('ops:queue-heartbeat'); } catch (\Throwable $e) {}
        $seenAt = $seen ? strtotime((string) $seen) : 0;
        $q = ScheduledMessage::where('domain', $domain)->where('user', $user)
            ->where('status', 'pending')->where('send_at', '<', now());
        if ($actor['role'] === 'agent') $q->where('created_by', 'agent:' . $actor['agent_id']);
        return response()->json([
            'worker_alive' => $seenAt > 0 && (time() - $seenAt) < 120,
            'worker_seen_at' => $seen,
            'worker_seen_ago_s' => $seenAt > 0 ? max(0, time() - $seenAt) : null,
            'overdue' => $q->count(),
        ]);
    }

    public function index(Request $request)
    {
        [$domain, $user] = $this->scope($request);
        $actor = $this->actor($request);
        $q = ScheduledMessage::where('domain', $domain)->where('user', $user);
        if ($actor['role'] === 'agent') $q->where('created_by', 'agent:' . $actor['agent_id']);
        return response()->json($q->orderBy('send_at')->get());
    }

    /** GET /api/scheduled/{id} */
    public function show(Request $request, ScheduledMessage $scheduled)
    {
        [$domain] = $this->scope($request);
        abort_unless($scheduled->domain === $domain, 403);
        $this->assertOwn($request, $scheduled);
        return response()->json($scheduled);
    }

    /** GET /api/scheduled/{id}/report — finished-action report. */
    public function report(Request $request, ScheduledMessage $scheduled)
    {
        [$domain] = $this->scope($request);
        abort_unless($scheduled->domain === $domain, 403);
        $this->assertOwn($request, $scheduled);
        return response()->json(static::reportFor($scheduled, true));
    }

    /**
     * Finished-action report, derived from recipients + send_log.
     * delivered = provider accepted (2xx); no carrier DLRs exist.
     */
    public static function reportFor(ScheduledMessage $m, bool $withRows = true): array
    {
        $latest = [];
        foreach ((array) ($m->send_log ?? []) as $l) {
            $d = preg_replace('/\D/', '', (string) ($l['phone'] ?? ''));
            if ($d !== '') $latest[$d] = $l;
        }
        $counts = ['queued' => 0, 'delivered' => 0, 'optout' => 0, 'failed' => 0];
        $rows = [];
        foreach ((array) ($m->recipients ?? []) as $r) {
            $d = preg_replace('/\D/', '', (string) ($r['phone'] ?? ''));
            if ($d === '') continue;
            $l = $latest[$d] ?? null;
            if ($l === null) $st = 'queued';
            elseif (!empty($l['ok'])) $st = 'delivered';
            elseif (str_starts_with((string) ($l['detail'] ?? ''), 'skipped: number opted out')) $st = 'optout';
            else $st = 'failed';
            $counts[$st]++;
            if ($withRows) $rows[] = ['phone' => (string) ($r['phone'] ?? ''), 'name' => (string) ($r['name'] ?? ''),
                'status' => $st, 'detail' => $l === null ? null : (string) ($l['detail'] ?? ''),
                'at' => $l['at'] ?? null];
        }
        $out = ['id' => $m->id, 'name' => $m->name, 'status' => $m->status,
            'from_number' => $m->from_number, 'type' => $m->type,
            'send_at' => $m->send_at?->toISOString(), 'counts' => $counts + ['total' => array_sum($counts)],
            'updated_at' => $m->updated_at?->toISOString()];
        if ($withRows) $out['recipients'] = $rows;
        return $out;
    }

    /**
     * POST /api/scheduled
     * { name?, message, from-number, type?: sms|mms, data?, mime-type?, size?,
     *   send_at: datetime (UTC ISO), timezone?: IANA name,
     *   targets: { contacts?: [...], group_ids?: [id], company?: "Acme",
     *              csv?: [{phone, name?, col1?, col2?, col3?}] } }
     */
    public function store(Request $request)
    {
        [$domain, $user] = $this->scope($request);
        $actor = $this->actor($request);
        $cbKey = $actor['role'] === 'agent' ? 'agent:' . $actor['agent_id'] : $user;
        $cbName = $actor['display_name'] ?? null;
        $data = $request->validate([
            'name'         => 'sometimes|nullable|string|max:120',
            'message'      => 'required|string|max:5000',
            'from-number'  => 'required|string',
            'type'         => 'sometimes|in:sms,mms',
            'data'         => 'sometimes|nullable|string',
            'mime-type'    => 'sometimes|nullable|string',
            'size'         => 'sometimes|nullable|integer|min:0|max:1048576',
            'send_at'      => 'required|date',
            'timezone'     => 'sometimes|string|max:60',
            'targets'      => 'required|array',
        ]);

        $this->assertMediaSize($data['data'] ?? null);
        $this->assertAgentNumber($request, (string) ($data['from-number'] ?? ''));
        // Per-account creation cap: scheduling is a future SMS budget.
        $storeKey = 'sched-store:' . $domain . ':' . ($actor['username'] ?? '?');
        if (RateLimiter::tooManyAttempts($storeKey, 10)) {
            return response()->json(['message' => 'Too many scheduled messages. Wait a minute and try again.'], 429);
        }
        RateLimiter::hit($storeKey, 60);
        $recipients = $this->expandTargets($domain, $data['targets']);
        if (empty($recipients)) {
            return response()->json(['message' => 'No recipients resolved from targets.'], 422);
        }

        $sendAt = $this->clampSendAt($data['send_at']);

        $m = ScheduledMessage::create([
            'domain' => $domain, 'user' => $user,
            'name' => $data['name'] ?? null,
            'message' => $data['message'], 'from_number' => $data['from-number'],
            'type' => $data['type'] ?? 'sms',
            'media_data' => $data['data'] ?? null,
            'media_mime' => $data['mime-type'] ?? null,
            'media_size' => $data['size'] ?? null,
            'send_at' => $sendAt,
            'timezone' => $data['timezone'] ?? 'US/Eastern',
            'targets' => $data['targets'],
            'recipients' => $recipients,   // snapshot: [{phone, name, vars}]
            'created_by' => $cbKey, 'created_by_name' => $cbName,
            'updated_by' => $cbKey, 'updated_by_name' => $cbName,
            'status' => 'pending',
            'send_log' => [],
        ]);

        // Dispatch ONE delayed job per recipient (1-by-1 semantics).
        foreach ($recipients as $i => $r) {
            // small stagger (2s each) to respect carrier rate limits
            SendScheduledMessage::dispatch($m->id, $i)->delay($sendAt->copy()->addSeconds($i * 2));
        }

        $this->audit($request, 'scheduled.created', ['scheduled_id' => $m->id, 'name' => $m->name]);
        DataChanged::send($domain, $user, 'scheduled', 'saved', $m->id);
        return response()->json($m, 201);
    }

    /** PUT /api/scheduled/{id} — edit pending only (re-dispatches jobs). */
    public function update(Request $request, ScheduledMessage $scheduled)
    {
        [$domain, $user] = $this->scope($request);
        abort_unless($scheduled->domain === $domain, 403);
        $this->assertOwn($request, $scheduled);
        abort_if($scheduled->status !== 'pending', 422, 'Only pending messages can be edited.');

        $data = $request->validate([
            'name' => 'sometimes|nullable|string|max:120',
            'message' => 'sometimes|string|max:5000',
            'send_at' => 'sometimes|date',
            'timezone' => 'sometimes|string|max:60',
        ]);
        if (isset($data['send_at'])) {
            $data['send_at'] = $this->clampSendAt($data['send_at']);
        }
        $actor = $this->actor($request);
        $data['updated_by'] = $actor['role'] === 'agent' ? 'agent:' . $actor['agent_id'] : $user;
        $data['updated_by_name'] = $actor['display_name'] ?? null;
        $scheduled->update($data);
        $this->audit($request, 'scheduled.updated', ['scheduled_id' => $scheduled->id, 'name' => $scheduled->name]);
        // NOTE: previously dispatched jobs check status + send_at at runtime;
        // cancelled/rescheduled messages are skipped by the job itself.
        if (isset($data['send_at'])) {
            $sendAt = \Carbon\Carbon::parse($scheduled->send_at);
            foreach ($scheduled->recipients as $i => $r) {
                SendScheduledMessage::dispatch($scheduled->id, $i)->delay($sendAt->copy()->addSeconds($i * 2));
            }
        }
        DataChanged::send($domain, $user, 'scheduled', 'saved', $scheduled->id);
        return response()->json($scheduled);
    }

    /** POST /api/scheduled/{scheduled}/cancel */
    public function cancel(Request $request, ScheduledMessage $scheduled)
    {
        [$domain, $user] = $this->scope($request);
        abort_unless($scheduled->domain === $domain, 403);
        $this->assertOwn($request, $scheduled);
        $scheduled->update(['status' => 'cancelled']);
        $this->audit($request, 'scheduled.cancelled', ['scheduled_id' => $scheduled->id, 'name' => $scheduled->name]);
        DataChanged::send($domain, $user, 'scheduled', 'saved', $scheduled->id);
        return response()->json($scheduled);
    }

    /**
     * POST /api/scheduled/{scheduled}/retry — re-queue the FAILED recipients
     * of a partial send. Failed log entries are dropped so completion
     * accounting restarts cleanly; successes are never re-sent.
     */
    public function retry(Request $request, ScheduledMessage $scheduled)
    {
        [$domain, $user] = $this->scope($request);
        abort_unless($scheduled->domain === $domain, 403);
        $this->assertOwn($request, $scheduled);
        abort_if($scheduled->status !== 'partial', 422, 'Only partially-failed sends can be retried.');

        $log = $scheduled->send_log ?? [];
        $failedPhones = collect($log)
            ->where('ok', false)
            ->pluck('phone')
            ->map(fn($p) => preg_replace('/\D/', '', (string) $p))
            ->filter()->unique()->values();
        if ($failedPhones->isEmpty()) {
            return response()->json(['message' => 'No failed recipients to retry.'], 422);
        }

        $scheduled->send_log = array_values(array_filter($log, fn($l) => !empty($l['ok'])));
        $scheduled->status = 'sending';
        $scheduled->save();

        $n = 0;
        foreach ($scheduled->recipients as $i => $r) {
            $digits = preg_replace('/\D/', '', (string) ($r['phone'] ?? ''));
            if ($failedPhones->contains($digits)) {
                SendScheduledMessage::dispatch($scheduled->id, $i)->delay(now()->addSeconds($n * 2));
                $n++;
            }
        }

        $fresh = $scheduled->fresh();
        $this->audit($request, 'scheduled.retried', ['scheduled_id' => $scheduled->id, 'name' => $scheduled->name]);
        DataChanged::send($domain, $user, 'scheduled', 'saved', $scheduled->id);
        return response()->json($fresh);
    }

    /**
     * POST /api/scheduled/{scheduled}/send-now — fire a pending message
     * immediately, ignoring its scheduled time. The old delayed jobs observe
     * the final status at runtime and skip (see SendScheduledMessage).
     */
    public function sendNow(Request $request, ScheduledMessage $scheduled)
    {
        [$domain, $user] = $this->scope($request);
        abort_unless($scheduled->domain === $domain, 403);
        $this->assertOwn($request, $scheduled);
        abort_unless($scheduled->status === 'pending', 422, 'Only pending messages can be sent now.');
        $scheduled->update(['send_at' => now(), 'status' => 'sending']);
        $this->audit($request, 'scheduled.send-now', ['scheduled_id' => $scheduled->id, 'name' => $scheduled->name]);
        foreach ($scheduled->recipients as $i => $r) {
            SendScheduledMessage::dispatch($scheduled->id, $i)->delay(now()->addSeconds($i * 2));
        }
        DataChanged::send($domain, $user, 'scheduled', 'saved', $scheduled->id);
        return response()->json($scheduled->fresh());
    }

    /** DELETE /api/scheduled/{id} */
    public function destroy(Request $request, ScheduledMessage $scheduled)
    {
        [$domain, $user] = $this->scope($request);
        abort_unless($scheduled->domain === $domain, 403);
        $this->assertOwn($request, $scheduled);
        $id = $scheduled->id; $nm = $scheduled->name;
        $scheduled->delete();
        $this->audit($request, 'scheduled.deleted', ['scheduled_id' => $id, 'name' => $nm]);
        DataChanged::send($domain, $user, 'scheduled', 'deleted', $id);
        return response()->json(['ok' => true]);
    }

    /**
     * Past date/time → now (send immediately) instead of a validation error.
     */
    /** Agents own only rows they created (created_by = 'agent:{id}'). Admins own all. */
    protected function assertOwn(Request $request, ScheduledMessage $scheduled): void
    {
        $actor = $this->actor($request);
        if ($actor['role'] === 'agent' && $scheduled->created_by !== 'agent:' . $actor['agent_id']) {
            abort(403, 'You can only manage scheduled messages you created.');
        }
    }

    protected function clampSendAt(string $sendAt): \Carbon\Carbon
    {
        $dt = \Carbon\Carbon::parse($sendAt);
        return $dt->lte(now()) ? now() : $dt;
    }

    /**
     * Expand { contacts, group_ids, company, csv } into a flat recipient list.
     * Company expansion uses Dynalink contacts filtered by company name.
     * CSV rows: [{phone, name?, col1?, col2?, col3?}] → vars for personalization.
     */
    protected function expandTargets(string $domain, array $targets): array
    {
        $out = [];
        $seen = [];

        $push = function ($phone, $name = '', $vars = [], $first = null, $last = null) use (&$out, &$seen) {
            $digits = preg_replace('/\D/', '', (string) $phone);
            if (!$digits || isset($seen[$digits])) return;
            $seen[$digits] = true;
            if ($first === null) { // CSV rows carry one name string — split it.
                $parts = preg_split('/\s+/', trim((string) $name));
                $first = $parts[0] ?? ''; $last = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';
            }
            $out[] = ['phone' => $digits, 'name' => $name, 'first_name' => $first, 'last_name' => $last,
                'vars' => $vars + ['col1' => '', 'col2' => '', 'col3' => '']];
        };

        foreach ($targets['contacts'] ?? [] as $c) {
            $phone = $c['phone'] ?? $c['phonenumber-cell'] ?? $c['phonenumber-work'] ?? null;
            $first = $c['name-first-name'] ?? $c['first_name'] ?? '';
            $last = $c['name-last-name'] ?? $c['last_name'] ?? '';
            $name = trim($first . ' ' . $last);
            if ($phone) $push($phone, $name, [], $first, $last);
        }

        foreach ($targets['group_ids'] ?? [] as $gid) {
            if (!self::isUuid($gid)) continue; // traversal defense: user-supplied id
            $file = "groups/" . preg_replace('/[^A-Za-z0-9._-]/', '_', $domain) . "/{$gid}.json";
            if (!\Illuminate\Support\Facades\Storage::exists($file)) continue;
            $g = json_decode(\Illuminate\Support\Facades\Storage::get($file), true);
            foreach ($g['members'] ?? [] as $m) {
                $mf = $m['name-first-name'] ?? ''; $ml = $m['name-last-name'] ?? '';
                $nm = trim($mf . ' ' . $ml);
                if (!empty($m['phone'])) $push($m['phone'], $nm, [], $mf, $ml);
            }
        }

        foreach ($targets['csv'] ?? [] as $r) {
            $push($r['phone'] ?? '', $r['name'] ?? '', [
                'col1' => (string) ($r['col1'] ?? ''),
                'col2' => (string) ($r['col2'] ?? ''),
                'col3' => (string) ($r['col3'] ?? ''),
            ]);
        }

        if (!empty($targets['company'])) {
            $ar = null;
            try { $ar = $this->actor(request()); } catch (\Throwable $e) {}
            if ($ar) {
                try {
                    $tok = $ar['token'] ?? $this->dtoken(request());
                    $contacts = app(\App\Services\DynalinkService::class)
                        ->contacts($tok, $ar['domain'], $ar['user']);
                    foreach ($contacts as $c) {
                        if (strcasecmp(trim($c['company'] ?? ''), trim($targets['company'])) !== 0) continue;
                        $phone = $c['phonenumber-cell'] ?? $c['phonenumber-work'] ?? $c['phonenumber-home'] ?? null;
                        $nm = trim(($c['name-first-name'] ?? '') . ' ' . ($c['name-last-name'] ?? ''));
                        if ($phone) $push($phone, $nm);
                    }
                } catch (\Throwable $e) {
                    // fall through with whatever we have
                }
            }
        }

        return array_values($out);
    }
}
