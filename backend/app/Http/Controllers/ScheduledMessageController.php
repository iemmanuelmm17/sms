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
        $unattributed = 0;
        try { $unattributed = (int) \Illuminate\Support\Facades\Cache::get('ops:unattributed-inbound', 0); } catch (\Throwable $e) {}
        $q = ScheduledMessage::where('domain', $domain)->where('user', $user)
            ->where('status', 'pending')->where('send_at', '<', now());
        if ($actor['role'] === 'agent') $q->where('created_by', 'agent:' . $actor['agent_id']);
        return response()->json([
            'worker_alive' => $seenAt > 0 && (time() - $seenAt) < 120,
            'worker_seen_at' => $seen,
            'worker_seen_ago_s' => $seenAt > 0 ? max(0, time() - $seenAt) : null,
            'overdue' => $q->count(),
            // Inbound webhook events that carried no tenant attribution — a
            // STOP in that shape could not be recorded, so this must stay ~0.
            'unattributed_inbound' => $unattributed,
        ]);
    }

    public function index(Request $request)
    {
        [$domain, $user] = $this->scope($request);
        $actor = $this->actor($request);
        $q = ScheduledMessage::where('domain', $domain)->where('user', $user);
        if ($actor['role'] === 'agent') $q->where('created_by', 'agent:' . $actor['agent_id']);
        // Newest first: most recently created at the top (send_at breaks ties),
        // so a message you just scheduled is visible without scrolling.
        return response()->json($q->orderByDesc('created_at')->orderByDesc('send_at')->get());
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
        // Image-only MMS schedules: Dynalink cannot carry text + media in
        // one MMS, so the body is optional exactly when media rides along.
        $mmsMedia = $request->input('type') === 'mms' && (string) $request->input('data', '') !== '';
        $data = $request->validate([
            'name'         => 'sometimes|nullable|string|max:120',
            'message'      => ($mmsMedia ? 'sometimes|nullable|string|max:5000' : 'required|string|max:5000'),
            'from-number'  => 'required|string',
            'type'         => 'sometimes|in:sms,mms',
            'data'         => 'sometimes|nullable|string',
            'mime-type'    => 'sometimes|nullable|string',
            'size'         => 'sometimes|nullable|integer|min:0|max:1048576',
            'send_at'      => 'required|date',
            'timezone'     => 'sometimes|string|max:60',
            'tcpa_script'  => 'sometimes|boolean',
            'include_optin' => 'sometimes|boolean',
            'targets'      => 'required|array',
            'recurrence'   => 'sometimes|nullable|in:daily,weekly,monthly',
            'recur_interval' => 'sometimes|integer|min:1|max:365',
            'recur_until'  => 'sometimes|nullable|date',
            'recur_occurrences' => 'sometimes|nullable|integer|min:1|max:999',
        ]);

        $this->assertMediaSize($data['data'] ?? null);
        $this->assertAgentNumber($request, (string) ($data['from-number'] ?? ''), 'new'); // scheduled new message
        // Per-account creation cap: scheduling is a future SMS budget.
        $storeKey = 'sched-store:' . $domain . ':' . ($actor['username'] ?? '?');
        if (RateLimiter::tooManyAttempts($storeKey, 10)) {
            return response()->json(['message' => 'Too many scheduled messages. Wait a minute and try again.'], 429);
        }
        RateLimiter::hit($storeKey, 60);
        $recipients = $this->expandTargets($domain, $data['targets']);
        // "Include opt-in contacts" ADDs the opt-in list to whatever was picked.
        if (!empty($data['include_optin'])) {
            $recipients = $this->mergeOptIns($domain, $recipients);
        }
        if (empty($recipients)) {
            return response()->json(['message' => 'No recipients resolved from targets.'], 422);
        }

        $sendAt = $this->clampSendAt($data['send_at']);

        $m = ScheduledMessage::create([
            'domain' => $domain, 'user' => $user,
            'name' => $data['name'] ?? null,
            'message' => (string) ($data['message'] ?? ''), 'from_number' => $data['from-number'],
            'type' => $data['type'] ?? 'sms',
            'media_data' => $data['data'] ?? null,
            'media_mime' => $data['mime-type'] ?? null,
            'media_size' => $data['size'] ?? null,
            'send_at' => $sendAt,
            'timezone' => $data['timezone'] ?? 'US/Eastern',
            'tcpa_script' => array_key_exists('tcpa_script', $data) ? (bool) $data['tcpa_script'] : true,
            'include_optin' => array_key_exists('include_optin', $data) ? (bool) $data['include_optin'] : false,
            'recurrence' => $data['recurrence'] ?? null,
            'recur_interval' => max(1, (int) ($data['recur_interval'] ?? 1)),
            'recur_until' => !empty($data['recur_until']) ? \Carbon\Carbon::parse($data['recur_until']) : null,
            'recur_occurrences' => !empty($data['recur_occurrences']) ? (int) $data['recur_occurrences'] : null,
            'recur_index' => 0,
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
            'tcpa_script' => 'sometimes|boolean',
            'include_optin' => 'sometimes|boolean',
            'recurrence' => 'sometimes|nullable|in:daily,weekly,monthly',
            'recur_interval' => 'sometimes|integer|min:1|max:365',
            'recur_until' => 'sometimes|nullable|date',
            'recur_occurrences' => 'sometimes|nullable|integer|min:1|max:999',
        ]);
        if (isset($data['send_at'])) {
            $data['send_at'] = $this->clampSendAt($data['send_at']);
        }
        if (array_key_exists('recur_until', $data)) {
            $data['recur_until'] = !empty($data['recur_until']) ? \Carbon\Carbon::parse($data['recur_until']) : null;
        }
        $actor = $this->actor($request);
        $data['updated_by'] = $actor['role'] === 'agent' ? 'agent:' . $actor['agent_id'] : $user;
        $data['updated_by_name'] = $actor['display_name'] ?? null;
        $wasIncluding = (bool) $scheduled->include_optin;
        $scheduled->update($data);
        // Switching "include opt-in contacts" ON later appends the opt-in list
        // to a still-pending message and dispatches the extra jobs. Existing
        // entries are never reindexed — queued jobs point at recipient indexes.
        // (Switching it back OFF can't recall jobs already in the queue.)
        if (!$wasIncluding && !empty($data['include_optin']) && $scheduled->status === 'pending') {
            $old = $scheduled->recipients ?? [];
            $merged = $this->mergeOptIns($domain, $old);
            $added = array_slice($merged, count($old));
            if ($added) {
                $base = $scheduled->send_at ? \Carbon\Carbon::parse($scheduled->send_at) : now();
                foreach ($added as $k => $r) {
                    $i = count($old) + $k;                       // keep existing indexes
                    SendScheduledMessage::dispatch($scheduled->id, $i)->delay($base->copy()->addSeconds($i * 2));
                }
                $scheduled->recipients = $merged;
                $scheduled->save();
            }
        }
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
     * POST /api/scheduled/{scheduled}/retry — re-queue everything that did
     * NOT confirm: recipients with a failed log entry AND recipients with no
     * entry at all (their job never ran — worker was down, timed out, etc).
     * Confirmed successes are never re-sent (send_log idempotency in the job
     * is the second line of defense). Failed entries are dropped so
     * completion accounting restarts cleanly.
     */
    public function retry(Request $request, ScheduledMessage $scheduled)
    {
        [$domain, $user] = $this->scope($request);
        abort_unless($scheduled->domain === $domain, 403);
        $this->assertOwn($request, $scheduled);
        abort_if(in_array($scheduled->status, ['sent', 'cancelled'], true), 422,
            'This message is finished — nothing to retry.');
        abort_if($scheduled->status === 'pending', 422,
            'Still pending — use Send now (or wait for its scheduled time).');

        $log = $scheduled->send_log ?? [];
        $digits = fn($p) => preg_replace('/\D/', '', (string) $p);
        $failedPhones = collect($log)->where('ok', false)->pluck('phone')->map($digits)->filter()->unique()->values();
        $confirmed = collect($log)->where('ok', true)->pluck('phone')->map($digits)->filter()->unique();
        $logged = collect($log)->pluck('phone')->map($digits)->filter()->unique();
        // Recipients whose job never produced ANY log entry (stuck in queue).
        $missing = collect($scheduled->recipients ?? [])
            ->map(fn($r) => $digits($r['phone'] ?? ''))->filter()
            ->reject(fn($d) => $logged->contains($d))->unique()->values();
        $targets = $failedPhones->merge($missing)->unique()->values();
        if ($targets->isEmpty()) {
            return response()->json(['message' => 'No failed or stuck recipients to retry.'], 422);
        }

        $scheduled->send_log = array_values(array_filter($log, fn($l) => !empty($l['ok'])));
        $scheduled->status = 'sending';
        $scheduled->save();

        $n = 0;
        foreach ($scheduled->recipients as $i => $r) {
            $d = $digits($r['phone'] ?? '');
            if ($d !== '' && $targets->contains($d) && !$confirmed->contains($d)) {
                SendScheduledMessage::dispatch($scheduled->id, $i)->delay(now()->addSeconds($n * 2));
                $n++;
            }
        }

        $fresh = $scheduled->fresh();
        $this->audit($request, 'scheduled.retried', ['scheduled_id' => $scheduled->id, 'name' => $scheduled->name, 'requeued' => $n]);
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
     * POST /api/scheduled/{scheduled}/cancel-series
     * Stop a recurring send: cancel every pending occurrence of the series and
     * clear the recurrence so nothing else spawns.
     */
    public function cancelSeries(Request $request, ScheduledMessage $scheduled)
    {
        [$domain, $user] = $this->scope($request);
        abort_unless($scheduled->domain === $domain, 403);
        $this->assertOwn($request, $scheduled);

        $root = $scheduled->parent_id ?: $scheduled->id;
        $rows = ScheduledMessage::where('domain', $domain)
            ->where(function ($q) use ($root) { $q->where('id', $root)->orWhere('parent_id', $root); })
            ->where('status', 'pending')
            ->get();

        $n = 0;
        foreach ($rows as $m) {
            $m->status = 'cancelled';
            $m->recurrence = null;      // belt and braces: no further spawning
            $m->save();
            $n += 1;
        }
        $this->audit($request, 'scheduled.series-cancelled', ['scheduled_id' => $scheduled->id, 'cancelled' => $n]);
        DataChanged::send($domain, $user, 'scheduled', 'saved', $scheduled->id);
        return response()->json(['ok' => true, 'cancelled' => $n]);
    }

    /**
     * Append every opted-in number that isn't already in the recipient list.
     * Compared on the last 10 digits so 10- and 11-digit forms dedupe.
     */
    protected function mergeOptIns(string $domain, array $recipients): array
    {
        $key10 = static function ($phone) {
            $d = preg_replace('/\D/', '', (string) $phone);
            if ($d === '') return '';
            return strlen($d) === 11 && str_starts_with($d, '1') ? substr($d, 1) : $d;
        };

        try {
            $optins = app(\App\Services\OptOutService::class)->optedInNumbers($domain);
        } catch (\Throwable $e) {
            return $recipients; // never block a send on the opt-in lookup
        }
        if (!$optins) return $recipients;

        $seen = [];
        foreach ($recipients as $r) {
            $k = $key10($r['phone'] ?? '');
            if ($k !== '') $seen[$k] = true;
        }
        foreach ($optins as $phone) {
            $k = $key10($phone);
            if ($k === '' || isset($seen[$k])) continue;
            $seen[$k] = true;
            $recipients[] = ['phone' => $k, 'name' => 'Opt-in contact', 'first_name' => '', 'last_name' => '',
                'vars' => ['col1' => '', 'col2' => '', 'col3' => '']];
        }
        return $recipients;
    }

    /**
     * Expand { contacts, group_ids, company, csv } into a flat recipient list.
     * Company expansion = contacts tagged with the company name (local mirror
     * first, provider fetch as fallback) PLUS members of groups linked to the
     * company via company_id.
     * CSV rows: [{phone, name?, col1?, col2?, col3?}] → vars for personalization.
     */
    protected function expandTargets(string $domain, array $targets): array
    {
        $out = [];
        $seen = [];

        $push = function ($phone, $name = '', $vars = [], $first = null, $last = null) use (&$out, &$seen) {
            $digits = preg_replace('/\D/', '', (string) $phone);
            if (!$digits) return;
            // One copy per number: 11-digit +1XXXXXXXXXX and 10-digit XXXXXXXXXX
            // are the same line, so dedupe on the normalized form.
            $key = (strlen($digits) === 11 && $digits[0] === '1') ? substr($digits, 1) : $digits;
            if (isset($seen[$key])) return;
            $seen[$key] = true;
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
            $cname  = trim((string) $targets['company']);
            $before = count($out);
            $ar = null;
            try { $ar = $this->actor(request()); } catch (\Throwable $e) {}

            // 1) Local contact mirror — the canonical mixed personal+shared
            //    list, so no provider round-trip (and no token) is needed.
            if ($ar && !empty($ar['user'])) {
                try {
                    $rows = \App\Models\Contact::where('domain', $domain)
                        ->where('user', $ar['user'])->get();
                    foreach ($rows as $c) {
                        if (strcasecmp(trim((string) $c->company), $cname) !== 0) continue;
                        $phone = $c->phone_cell ?: ($c->phone_work ?: ($c->phone_home ?: null));
                        if ($phone) {
                            $push($phone, trim((string) $c->first_name . ' ' . (string) $c->last_name),
                                [], (string) $c->first_name, (string) $c->last_name);
                        }
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Scheduled company expansion (mirror) failed: ' . $e->getMessage());
                }
            }

            // 2) Groups linked to the company (company_id) — a company
            //    "contains" its tagged contacts AND its linked groups, the
            //    same convention the Companies page shows. Member snapshots
            //    carry their own resolved phone.
            try {
                $companyId = '';
                $cdir = 'companies/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $domain);
                if (\Illuminate\Support\Facades\Storage::exists($cdir)) {
                    foreach (\Illuminate\Support\Facades\Storage::files($cdir) as $file) {
                        if (pathinfo($file, PATHINFO_EXTENSION) !== 'json') continue;
                        $co = \App\Services\JsonFileStore::read($file);
                        if (is_array($co) && strcasecmp(trim((string) ($co['name'] ?? '')), $cname) === 0) {
                            $companyId = (string) ($co['id'] ?? '');
                            break;
                        }
                    }
                }
                if ($companyId !== '') {
                    $gdir = 'groups/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $domain);
                    if (\Illuminate\Support\Facades\Storage::exists($gdir)) {
                        foreach (\Illuminate\Support\Facades\Storage::files($gdir) as $file) {
                            if (pathinfo($file, PATHINFO_EXTENSION) !== 'json') continue;
                            $g = \App\Services\JsonFileStore::read($file);
                            if (!is_array($g) || (string) ($g['company_id'] ?? '') !== $companyId) continue;
                            foreach ($g['members'] ?? [] as $m) {
                                $mf = $m['name-first-name'] ?? ''; $ml = $m['name-last-name'] ?? '';
                                if (!empty($m['phone'])) $push($m['phone'], trim($mf . ' ' . $ml), [], $mf, $ml);
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Scheduled company expansion (groups) failed: ' . $e->getMessage());
            }

            // 3) Fallback: live provider fetch when the mirror contributed
            //    nothing (fresh install whose contacts page was never opened).
            if (count($out) === $before && $ar) {
                try {
                    $tok = $ar['token'] ?? $this->dtoken(request());
                    $contacts = app(\App\Services\DynalinkService::class)
                        ->contacts($tok, $ar['domain'], $ar['user']);
                    foreach ($contacts as $c) {
                        if (strcasecmp(trim($c['company'] ?? ''), $cname) !== 0) continue;
                        $phone = $c['phonenumber-cell'] ?? $c['phonenumber-work'] ?? $c['phonenumber-home'] ?? null;
                        $nm = trim(($c['name-first-name'] ?? '') . ' ' . ($c['name-last-name'] ?? ''));
                        if ($phone) $push($phone, $nm);
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Scheduled company expansion (provider) failed: ' . $e->getMessage());
                }
            }
        }

        return array_values($out);
    }
}
