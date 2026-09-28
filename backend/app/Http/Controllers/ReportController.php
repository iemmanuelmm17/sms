<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesActor;
use App\Models\Agent;
use App\Models\SentMessageLog;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * SMS send-volume analytics over sent_message_logs (one row per
 * successful send, category stamped at send time — no inference).
 *
 * Same methods serve both portals: tenant routes resolve the caller's
 * own scope (agents → 403), super routes take ?tenant_id= (all | id |
 * legacy). Every view honors from/to (max 31 days), categories[] and
 * agent_ids[] filters.
 */
class ReportController extends Controller
{
    use ResolvesActor;

    private const MAX_DAYS = 31;
    private const CACHE_TTL = 300; // reports may lag sends by 5 min

    /** Aggregate views are pure functions of (scope, query) — safe to cache. */
    protected function cached(Request $r, string $view, array $scope, \Closure $fn)
    {
        // The epoch changes whenever a send is logged, so a report refreshes the
        // moment something new is sent instead of lagging by the full TTL.
        try { $epoch = (int) Cache::get(SentMessageLog::REPORT_EPOCH_KEY, 0); } catch (\Throwable $e) { $epoch = 0; }
        $key = 'report:' . $view . ':' . $epoch . ':' . md5(json_encode([$scope, $r->query()]));
        return Cache::remember($key, self::CACHE_TTL, $fn);
    }

    // ---------------- scope ----------------

    /** Admin portal: own tenant, or legacy domain/user. Agents → 403. */
    protected function adminScope(Request $r): array
    {
        $a = $this->actor($r);
        $this->requireAdmin($a);
        if (isset($a['tenant_id'])) return ['mode' => 'tenant', 'tenant_id' => (int) $a['tenant_id']];
        return ['mode' => 'legacy', 'domain' => $a['domain'], 'user' => $a['user']];
    }

    /** Super portal: everything, one tenant, or legacy-only rows. */
    protected function superScope(Request $r): array
    {
        $t = $r->query('tenant_id');
        if ($t === null || $t === '' || $t === 'all') return ['mode' => 'all'];
        if ($t === 'legacy') return ['mode' => 'legacy_all'];
        $tenant = Tenant::find($t);
        abort_unless($tenant, 404, 'Tenant not found.');
        return ['mode' => 'tenant', 'tenant_id' => (int) $tenant->id];
    }

    /**
     * Portal agents get reporting for THEIR numbers only.
     *
     * Same tenant rows an admin would see, then narrowed to the agent's
     * visible set (own portal-assigned numbers + granted shared ones) via the
     * same AgentAccess used by the inbox and the send guard — so reporting can
     * never expose traffic the agent cannot already read.
     *
     * A visible set of [] means "no numbers", which must produce an EMPTY
     * report, never an unfiltered one.
     */
    protected function agentScope(Request $r): array
    {
        $a = $this->actor($r);
        $numbers = [];
        try {
            $numbers = app(\App\Services\AgentAccess::class)->readableNumbers(
                $a['domain'], $a['ext'] ?? $a['user'], $this->dtoken($r));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('agent reporting: visibleNumbers failed',
                ['domain' => $a['domain'], 'error' => $e->getMessage()]);
            $numbers = [];   // fail closed
        }
        $tenant = \App\Models\Tenant::where('domain', $a['domain'])->first();
        return [
            'mode' => 'agent',
            'tenant_id' => $tenant?->id,
            'domain' => $a['domain'],
            'numbers' => $numbers,
        ];
    }

    protected function scope(Request $r): array
    {
        if ($r->attributes->get('superadmin')) return $this->superScope($r);
        $a = $this->actor($r);
        if (($a['role'] ?? '') === 'agent') {
            // Portal agents are allowed; legacy local agents still 403.
            abort_unless(!empty($a['portal_auth']), 403, 'Reporting is not available for this account.');
            return $this->agentScope($r);
        }
        return $this->adminScope($r);
    }

    protected function applyScope($q, array $scope)
    {
        if ($scope['mode'] === 'agent') {
            if ($scope['tenant_id']) $q->where('tenant_id', $scope['tenant_id']);
            else $q->where('domain', $scope['domain']);
            // Plain, indexed whereIn. Legacy rows with formatted numbers are
            // normalised once by migration 000048, so no per-query string
            // munging is needed — the previous nested REPLACE() was both
            // unindexable and invalid on SQLite.
            $want = $scope['numbers'];
            if ($want === []) return $q->whereRaw('1 = 0');   // fail closed
            return $q->whereIn('from_number', $want);
        }
        if ($scope['mode'] === 'tenant') return $q->where('tenant_id', $scope['tenant_id']);
        if ($scope['mode'] === 'legacy') {
            return $q->where('domain', $scope['domain'])->where('user', $scope['user'])->whereNull('tenant_id');
        }
        if ($scope['mode'] === 'legacy_all') return $q->whereNull('tenant_id');
        return $q; // all
    }

    // ---------------- filters ----------------

    /**
     * Day bounds for the report. Dates arrive as Y-m-d in the VIEWER's timezone
     * (the browser sends its own), so they're interpreted there and converted
     * to the storage timezone — otherwise "Today" resolves to the wrong window
     * for anyone whose day doesn't match the server's (e.g. UTC+8 vs UTC).
     */
    protected function tzOf(Request $r): string
    {
        $tz = trim((string) $r->query('tz', ''));
        if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) return $tz;
        return (string) (config('app.timezone') ?: 'UTC');
    }

    protected function storageTz(): string
    {
        return (string) (config('app.timezone') ?: 'UTC');
    }

    /** [from, to] as day bounds; default = last 7 days incl. today. */
    protected function range(Request $r): array
    {
        $tz = $this->tzOf($r);
        $store = $this->storageTz();
        try {
            $to = $r->query('to')
                ? Carbon::createFromFormat('Y-m-d', (string) $r->query('to'), $tz)->endOfDay()->tz($store)
                : now($tz)->endOfDay()->tz($store);
            $from = $r->query('from')
                ? Carbon::createFromFormat('Y-m-d', (string) $r->query('from'), $tz)->startOfDay()->tz($store)
                : now($tz)->subDays(6)->startOfDay()->tz($store);
        } catch (\Throwable $e) {
            abort(response()->json(['message' => 'Dates must be Y-m-d.'], 422));
        }
        if ($to->lt($from)) abort(response()->json(['message' => '`to` is before `from`.'], 422));
        if ((int) abs($from->diffInDays($to)) > self::MAX_DAYS - 1) {
            abort(response()->json(['message' => 'Date range cannot exceed 31 days.'], 422));
        }
        return [$from, $to];
    }

    protected function categories(Request $r): ?array
    {
        $c = $r->query('categories');
        if ($c === null || $c === '') return null;
        $list = array_values(array_unique(is_array($c) ? $c : explode(',', (string) $c)));
        foreach ($list as $v) {
            abort_unless(in_array($v, SentMessageLog::CATEGORIES, true),
                response()->json(['message' => "Unknown category: {$v}."], 422));
        }
        return $list;
    }

    protected function agentIds(Request $r): ?array
    {
        $a = $r->query('agent_ids');
        if ($a === null || $a === '') return null;
        $list = is_array($a) ? $a : explode(',', (string) $a);
        return array_values(array_unique(array_map('intval',
            array_filter($list, fn($v) => is_numeric($v) && (int) $v > 0))));
    }

    protected function applyFilters($q, Request $r)
    {
        if (($cats = $this->categories($r)) !== null) $q->whereIn('category', $cats);
        if (($ids = $this->agentIds($r)) !== null) $q->whereIn('agent_id', $ids);
        return $q;
    }

    // ---------------- views ----------------

    /** Totals + category mix + previous-period delta. */
    public function summary(Request $request)
    {
        $scope = $this->scope($request);
        [$from, $to] = $this->range($request);
        return response()->json($this->cached($request, 'summary', $scope, function () use ($request, $scope, $from, $to) {
            $base = fn() => $this->applyFilters($this->applyScope(SentMessageLog::query(), $scope), $request);
            $cur = $base()->whereBetween('sent_at', [$from, $to]);
            $total = (clone $cur)->count();
            $byCat = array_fill_keys(SentMessageLog::CATEGORIES, 0);
            foreach ((clone $cur)->selectRaw('category, COUNT(*) c')->groupBy('category')->pluck('c', 'category') as $cat => $c) {
                if (isset($byCat[$cat])) $byCat[$cat] = (int) $c;
            }
            $days = (int) abs($from->diffInDays($to)) + 1;
            $prev = $base()->whereBetween('sent_at', [$from->copy()->subDays($days), $from->copy()->subSecond()])->count();
            $delta = $prev > 0 ? round(($total - $prev) / $prev * 100, 1) : null;
            $since = $this->applyScope(SentMessageLog::query(), $scope)->min('sent_at');
            return [
                'from' => $from->toDateString(), 'to' => $to->toDateString(),
                'total' => $total, 'by_category' => $byCat,
                'prev_total' => $prev, 'delta_pct' => $delta,
                'tracking_since' => $since ? Carbon::parse($since)->toDateString() : null,
            ];
        }));
    }

    /** Per-bucket volume (daily ≤14 days, else Mon–Sun weeks), gaps zero-filled. */
    public function trend(Request $request)
    {
        $scope = $this->scope($request);
        [$from, $to] = $this->range($request);
        $days = (int) abs($from->diffInDays($to)) + 1;
        return response()->json($this->cached($request, 'trend', $scope, function () use ($request, $scope, $from, $to, $days) {
        $rows = $this->applyFilters($this->applyScope(SentMessageLog::query(), $scope), $request)
            ->whereBetween('sent_at', [$from, $to])
            ->selectRaw('date(sent_at) d, category, COUNT(*) c')
            ->groupBy('d', 'category')->orderBy('d')->get();
        $grid = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $grid[$d->toDateString()] = array_merge(['bucket' => $d->toDateString(), 'total' => 0],
                array_fill_keys(SentMessageLog::CATEGORIES, 0));
        }
        foreach ($rows as $r) {
            if (!isset($grid[$r->d]) || !isset($grid[$r->d][$r->category])) continue;
            $grid[$r->d][$r->category] = (int) $r->c;
            $grid[$r->d]['total'] += (int) $r->c;
        }
        $points = array_values($grid);
        $bucket = 'day';
        if ($days > 14) {
            $bucket = 'week';
            $weeks = [];
            foreach ($points as $p) {
                $wk = Carbon::parse($p['bucket'])->startOfWeek()->toDateString();
                if (!isset($weeks[$wk])) {
                    $weeks[$wk] = array_merge(['bucket' => $wk, 'total' => 0],
                        array_fill_keys(SentMessageLog::CATEGORIES, 0));
                }
                foreach (SentMessageLog::CATEGORIES as $c) $weeks[$wk][$c] += $p[$c];
                $weeks[$wk]['total'] += $p['total'];
            }
            $points = array_values($weeks);
        }
            return ['bucket' => $bucket, 'points' => $points];
        }));
    }

    /** One row per sending agent + an aggregate Admin row (manual sends). */
    public function byAgent(Request $request)
    {
        $scope = $this->scope($request);
        [$from, $to] = $this->range($request);
        return response()->json($this->cached($request, 'byAgent', $scope, function () use ($request, $scope, $from, $to) {
        $q = $this->applyFilters($this->applyScope(SentMessageLog::query(), $scope), $request)
            ->whereBetween('sent_at', [$from, $to]);
        $agents = (clone $q)->whereNotNull('agent_id')
            ->selectRaw('agent_id, MAX(actor_name) name, COUNT(*) total')
            ->selectRaw("SUM(CASE WHEN category='new_sms' THEN 1 ELSE 0 END) new_sms")
            ->selectRaw("SUM(CASE WHEN category='regular_reply' THEN 1 ELSE 0 END) regular_reply")
            ->selectRaw("SUM(CASE WHEN category='mass_sms' THEN 1 ELSE 0 END) mass_triggered")
            ->groupBy('agent_id')->get();
        $rows = [];
        foreach ($agents as $a) {
            $rows[] = ['agent_id' => (int) $a->agent_id, 'agent_name' => $a->name ?? ('Agent #' . $a->agent_id),
                'total' => (int) $a->total, 'new_sms' => (int) $a->new_sms,
                'regular_reply' => (int) $a->regular_reply, 'mass_triggered' => (int) $a->mass_triggered];
        }
        if ($this->agentIds($request) === null) {
            $admin = (clone $q)->whereNull('agent_id')
                ->whereIn('category', [SentMessageLog::NEW_SMS, SentMessageLog::REGULAR_REPLY])
                ->selectRaw('COUNT(*) total')
                ->selectRaw("SUM(CASE WHEN category='new_sms' THEN 1 ELSE 0 END) new_sms")
                ->selectRaw("SUM(CASE WHEN category='regular_reply' THEN 1 ELSE 0 END) regular_reply")
                ->first();
            if ($admin && (int) $admin->total > 0) {
                $rows[] = ['agent_id' => null, 'agent_name' => 'Admin',
                    'total' => (int) $admin->total, 'new_sms' => (int) $admin->new_sms,
                    'regular_reply' => (int) $admin->regular_reply, 'mass_triggered' => 0];
            }
        }
            return ['rows' => $rows];
        }));
    }

    /** One row per sending number with a category breakdown. */
    public function byNumber(Request $request)
    {
        $scope = $this->scope($request);
        [$from, $to] = $this->range($request);
        return response()->json($this->cached($request, 'byNumber', $scope, function () use ($request, $scope, $from, $to) {
        $rows = $this->applyFilters($this->applyScope(SentMessageLog::query(), $scope), $request)
            ->whereBetween('sent_at', [$from, $to])
            ->selectRaw('from_number, COUNT(*) total')
            ->selectRaw("SUM(CASE WHEN category='new_sms' THEN 1 ELSE 0 END) new_sms")
            ->selectRaw("SUM(CASE WHEN category='regular_reply' THEN 1 ELSE 0 END) regular_reply")
            ->selectRaw("SUM(CASE WHEN category='mass_sms' THEN 1 ELSE 0 END) mass_sms")
            ->selectRaw("SUM(CASE WHEN category='auto_reply' THEN 1 ELSE 0 END) auto_reply")
            ->groupBy('from_number')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['from_number' => $r->from_number, 'total' => (int) $r->total,
                'new_sms' => (int) $r->new_sms, 'regular_reply' => (int) $r->regular_reply,
                'mass_sms' => (int) $r->mass_sms, 'auto_reply' => (int) $r->auto_reply];
        }
            return ['rows' => $out];
        }));
    }

    /** Message-level rows for drill-down + CSV export (newest first). */
    public function detail(Request $request)
    {
        $scope = $this->scope($request);
        [$from, $to] = $this->range($request);
        return response()->json($this->cached($request, 'detail', $scope, function () use ($request, $scope, $from, $to) {
        $per = min(max((int) $request->query('per_page', 50), 1), 5000);
        $page = max((int) $request->query('page', 1), 1);
        $q = $this->applyFilters($this->applyScope(SentMessageLog::query(), $scope), $request)
            ->whereBetween('sent_at', [$from, $to])->orderByDesc('sent_at')->orderByDesc('id');
        if ($request->query('agent_id') !== null && $request->query('agent_id') !== '') {
            $q->where('agent_id', (int) $request->query('agent_id'));
        }
        $total = (clone $q)->count();
        $data = (clone $q)->forPage($page, $per)->get([
            'id', 'tenant_id', 'sent_at', 'category', 'agent_id', 'actor_name',
            'from_number', 'to_number', 'type', 'scheduled_message_id', 'auto_reply_id', 'session_id',
        ]);
            return ['data' => $data->toArray(), 'meta' => ['total' => $total, 'page' => $page, 'per_page' => $per]];
        }));
    }

    /** Super only: per-tenant volume + active-agent counts for the range. */
    public function tenants(Request $request)
    {
        abort_unless($request->attributes->get('superadmin'), 403, 'Super admins only.');
        [$from, $to] = $this->range($request);
        return response()->json($this->cached($request, 'tenants', ['mode' => 'all'], function () use ($request, $from, $to) {
        $q = $this->applyFilters(SentMessageLog::query(), $request)->whereBetween('sent_at', [$from, $to]);
        $vol = (clone $q)->whereNotNull('tenant_id')
            ->selectRaw('tenant_id, COUNT(*) total')
            ->selectRaw("SUM(CASE WHEN category='new_sms' THEN 1 ELSE 0 END) new_sms")
            ->selectRaw("SUM(CASE WHEN category='regular_reply' THEN 1 ELSE 0 END) regular_reply")
            ->selectRaw("SUM(CASE WHEN category='mass_sms' THEN 1 ELSE 0 END) mass_sms")
            ->selectRaw("SUM(CASE WHEN category='auto_reply' THEN 1 ELSE 0 END) auto_reply")
            ->groupBy('tenant_id')->get()->keyBy('tenant_id');
        $legacy = (clone $q)->whereNull('tenant_id')
            ->selectRaw('COUNT(*) total')
            ->selectRaw("SUM(CASE WHEN category='new_sms' THEN 1 ELSE 0 END) new_sms")
            ->selectRaw("SUM(CASE WHEN category='regular_reply' THEN 1 ELSE 0 END) regular_reply")
            ->selectRaw("SUM(CASE WHEN category='mass_sms' THEN 1 ELSE 0 END) mass_sms")
            ->selectRaw("SUM(CASE WHEN category='auto_reply' THEN 1 ELSE 0 END) auto_reply")
            ->first();
        $tenants = Tenant::orderBy('name')->get(['id', 'name', 'domain', 'dynalink_user']);
        $agentCounts = Agent::selectRaw('domain, `user`, COUNT(*) c')->where('status', 'active')
            ->groupBy('domain', 'user')->get()->keyBy(fn($r) => $r->domain . '|' . $r->user);
        $rows = [];
        foreach ($tenants as $t) {
            $v = $vol->get($t->id);
            $rows[] = ['tenant_id' => $t->id, 'tenant_name' => $t->name,
                'total' => (int) ($v->total ?? 0), 'new_sms' => (int) ($v->new_sms ?? 0),
                'regular_reply' => (int) ($v->regular_reply ?? 0), 'mass_sms' => (int) ($v->mass_sms ?? 0),
                'auto_reply' => (int) ($v->auto_reply ?? 0),
                'active_agents' => (int) ($agentCounts->get($t->domain . '|' . $t->dynalink_user)->c ?? 0)];
        }
        if ($legacy && (int) $legacy->total > 0) {
            $rows[] = ['tenant_id' => 'legacy', 'tenant_name' => 'Legacy (no tenant)',
                'total' => (int) $legacy->total, 'new_sms' => (int) $legacy->new_sms,
                'regular_reply' => (int) $legacy->regular_reply, 'mass_sms' => (int) $legacy->mass_sms,
                'auto_reply' => (int) $legacy->auto_reply, 'active_agents' => null];
        }
            return ['rows' => $rows];
        }));
    }
}
