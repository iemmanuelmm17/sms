<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesActor;
use App\Models\AgentIdentity;
use App\Models\AgentNumberGrant;
use App\Models\AuditLog;
use App\Services\AgentAccess;
use App\Services\CompanySettingsService;
use App\Services\DynalinkService;
use Illuminate\Http\Request;

/**
 * Admin management of portal-authenticated agents.
 *
 * These endpoints never touch credentials — the Dynalink portal owns
 * authentication. They manage only the local state: the active/disabled kill
 * switch and which SHARED numbers each extension may use.
 */
class AgentIdentityController extends Controller
{
    use ResolvesActor;

    public function __construct(
        protected DynalinkService $dynalink,
        protected AgentAccess $access,
        protected CompanySettingsService $settings,
    ) {}

    protected function admin(Request $r): array
    {
        $a = $this->actor($r);
        abort_unless(($a['role'] ?? '') === 'admin', 403, 'Admins only.');
        return $a;
    }

    /**
     * GET /api/agent-identities — roster with grants and derived own-numbers.
     *
     * Own numbers come from the live provider map, so a re-provision in the
     * portal shows up here without any local edit.
     */
    public function index(Request $request)
    {
        $a = $this->admin($request);

        $owners = [];
        try {
            $owners = $this->dynalink->numberOwners($this->dtoken($request), $a['domain']);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('agent roster: numberOwners failed',
                ['domain' => $a['domain'], 'error' => $e->getMessage()]);
        }

        $grants = AgentNumberGrant::where('domain', $a['domain'])->get()->groupBy('ext');
        $shared = $this->settings->sharedNumbers($a['domain']);

        // Per-number flags for the UI: digits => {reply, create} (the key's
        // presence is the view grant).
        $detail = [];
        foreach ($grants as $ext => $g) {
            foreach ($g as $row) {
                $d = AgentNumberGrant::normalizeNumber($row->number);
                if ($d !== '') $detail[(string) $ext][$d] = ['reply' => (bool) $row->reply, 'create' => (bool) $row->create];
            }
        }

        $rows = AgentIdentity::where('domain', $a['domain'])
            ->orderBy('ext')->get()->map(function ($i) use ($owners, $grants, $shared, $detail) {
                $granted = ($grants[$i->ext] ?? collect())->pluck('number')->values()->all();
                return [
                    'id'             => $i->id,
                    'ext'            => $i->ext,
                    'display_name'   => $i->displayName(),
                    'first_name'     => $i->first_name,
                    'last_name'      => $i->last_name,
                    'email'          => $i->email,
                    'department'     => $i->department,
                    'site'           => $i->site,
                    'profile_synced_at' => $i->profile_synced_at?->toJSON(),
                    // Seconds left on a failed-login lockout (0 = not locked).
                    'locked_secs'    => \App\Services\LockoutService::remainingFor(
                        mb_strtolower($i->ext . '@' . $i->domain)),
                    'tag_color'      => $i->tag_color,
                    'status'         => $i->status,
                    'last_seen_at'   => $i->last_seen_at?->toJSON(),
                    'first_login_at' => $i->first_login_at?->toJSON(),
                    'own_numbers'    => $this->access->ownNumbers($owners, $i->ext),
                    'granted'        => $granted,
                    'grants_detail'  => $detail[$i->ext] ?? [],
                    // granted but no longer shared: surfaced so admins can see
                    // why an agent lost access without a grant being deleted
                    'granted_inactive' => array_values(array_diff($granted, $shared)),
                ];
            })->all();

        // Extensions with grants but no identity yet (pre-granted, never logged in).
        $known = array_column($rows, 'ext');
        foreach ($grants as $ext => $g) {
            if (in_array((string) $ext, $known, true)) continue;
            $rows[] = [
                'id' => null, 'ext' => (string) $ext, 'display_name' => (string) $ext,
                'tag_color' => '#94a3b8', 'status' => 'pending',
                'last_seen_at' => null, 'first_login_at' => null,
                'own_numbers' => $this->access->ownNumbers($owners, (string) $ext),
                'granted' => $g->pluck('number')->values()->all(),
                'grants_detail' => $detail[(string) $ext] ?? [],
                'granted_inactive' => array_values(array_diff($g->pluck('number')->all(), $shared)),
            ];
        }

        return response()->json(['agents' => $rows, 'shared' => array_values($shared)]);
    }

    /**
     * POST /api/agent-identities/{ext}/resync — refresh one user from the portal.
     *
     * Per-user rather than all-at-once so the UI can lazy-load: the roster
     * renders immediately and each row is refreshed on demand, instead of
     * blocking on one request that fans out across every extension.
     */
    public function resync(Request $request, string $ext)
    {
        $a = $this->admin($request);
        $ext = AgentIdentity::normalizeExt($ext);
        $i = AgentIdentity::where('domain', $a['domain'])->where('ext', $ext)->first();
        abort_unless($i, 404, 'No such user.');

        try {
            $changed = $i->syncProfile($this->dtoken($request));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('resync failed',
                ['domain' => $a['domain'], 'ext' => $ext, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Could not reach the portal. Try again in a moment.'], 503);
        }
        if ($changed) {
            AuditLog::record($a['domain'], 'admin', null, $a['display_name'] ?? $a['username'],
                'agent.profile-resynced', ['ext' => $ext], $request->ip());
        }
        $i->refresh();
        return response()->json(['ok' => true, 'changed' => $changed, 'agent' => [
            'ext' => $i->ext, 'display_name' => $i->displayName(),
            'first_name' => $i->first_name, 'last_name' => $i->last_name,
            'email' => $i->email, 'department' => $i->department, 'site' => $i->site,
            'profile_synced_at' => $i->profile_synced_at?->toJSON(),
        ]]);
    }

    /**
     * POST /api/agent-identities/{ext}/unlock — clear a failed-login lockout.
     *
     * Without this an admin can only wait out the window, which is painful
     * when someone fat-fingers their portal password before a shift.
     */
    public function unlock(Request $request, string $ext)
    {
        $a = $this->admin($request);
        $ext = AgentIdentity::normalizeExt($ext);
        $key = mb_strtolower($ext . '@' . $a['domain']);
        $cleared = \App\Services\LockoutService::clearUser($key);

        AuditLog::record($a['domain'], 'admin', null, $a['display_name'] ?? $a['username'],
            'agent.unlocked', ['ext' => $ext, 'attempts_cleared' => $cleared], $request->ip());

        return response()->json(['ok' => true, 'cleared' => $cleared, 'locked_secs' => 0]);
    }

    /** PUT /api/agent-identities/{ext}/status — the kill switch. */
    public function status(Request $request, string $ext)
    {
        $a = $this->admin($request);
        $data = $request->validate(['status' => 'required|in:active,disabled']);
        $ext = AgentIdentity::normalizeExt($ext);

        $i = AgentIdentity::where('domain', $a['domain'])->where('ext', $ext)->first();
        abort_unless($i, 404, 'No such agent.');

        $i->status = $data['status'];
        $i->save();

        AuditLog::record($a['domain'], 'admin', null, $a['display_name'] ?? $a['username'],
            'agent.status-changed', ['ext' => $ext, 'status' => $data['status']], $request->ip());

        return response()->json(['ok' => true, 'status' => $i->status]);
    }

    /**
     * PUT /api/agent-identities/{ext}/grants — replace the grant set.
     *
     * Two accepted shapes:
     *   { "numbers": ["5551234567"] }                      legacy: full access
     *   { "grants":  [{ "number": "5551234567",
     *                    "view": true, "reply": true, "create": false }] }
     *
     * view=false (or an absent number) REMOVES the grant. Only SHARED numbers
     * can be granted: an agent's own numbers are implicit, and granting a
     * private number belonging to someone else would be a way to bypass the
     * sharing model entirely.
     */
    public function grants(Request $request, string $ext)
    {
        $a = $this->admin($request);
        $data = $request->validate([
            'numbers'  => 'present_without:grants|array',
            'numbers.*' => 'string|max:32',
            'grants'   => 'present_without:numbers|array',
            'grants.*.number' => 'string|max:32',
            'grants.*.view'   => 'sometimes|boolean',
            'grants.*.reply'  => 'sometimes|boolean',
            'grants.*.create' => 'sometimes|boolean',
        ]);
        $ext = AgentIdentity::normalizeExt($ext);
        abort_if($ext === '', 422, 'Extension is required.');

        $shared = array_flip($this->settings->sharedNumbers($a['domain']));

        // Normalize both shapes to digits => {view, reply, create}.
        $want = [];
        if (array_key_exists('numbers', $data)) {
            foreach ($data['numbers'] as $n) {
                $d = AgentNumberGrant::normalizeNumber($n);
                if ($d !== '' && !isset($want[$d])) $want[$d] = ['view' => true, 'reply' => true, 'create' => true];
            }
        } else {
            foreach ($data['grants'] as $g) {
                $d = AgentNumberGrant::normalizeNumber($g['number'] ?? null);
                if ($d === '') continue;
                $view = (bool) ($g['view'] ?? true);
                if (!isset($want[$d]) || $view) {
                    $want[$d] = [
                        'view'   => $view,
                        'reply'  => $view && (bool) ($g['reply'] ?? false),
                        'create' => $view && (bool) ($g['create'] ?? false),
                    ];
                }
            }
        }

        foreach ($want as $d => $f) {
            if ($f['view'] && !isset($shared[$d])) {
                return response()->json([
                    'message' => 'Only shared numbers can be granted. Mark the number shared first.',
                ], 422);
            }
        }

        $haveRows = AgentNumberGrant::where('domain', $a['domain'])->where('ext', $ext)->get()->keyBy(fn ($r) => AgentNumberGrant::normalizeNumber($r->number));

        foreach ($want as $d => $f) {
            if (!$f['view']) {
                if ($haveRows->has($d)) $haveRows->get($d)->delete();
                continue;
            }
            if ($haveRows->has($d)) {
                $row = $haveRows->get($d);
                if ((bool) $row->reply !== $f['reply'] || (bool) $row->create !== $f['create']) {
                    $row->forceFill(['reply' => $f['reply'], 'create' => $f['create']])->save();
                }
            } else {
                AgentNumberGrant::create([
                    'domain' => $a['domain'], 'ext' => $ext, 'number' => $d,
                    'reply' => $f['reply'], 'create' => $f['create'],
                    'granted_by' => $a['display_name'] ?? $a['username'] ?? null,
                ]);
            }
        }
        foreach ($haveRows->keys() as $d) {
            if (!array_key_exists($d, $want)) $haveRows->get($d)->delete();
        }

        $saved = array_keys(array_filter($want, fn ($f) => $f['view']));
        // Audit any change at all — added/removed view grants AND reply/create
        // flag flips on an unchanged set.
        $before = [];
        foreach ($haveRows as $row) {
            $d = AgentNumberGrant::normalizeNumber($row->number);
            if ($d !== '') $before[$d] = ['reply' => (bool) $row->reply, 'create' => (bool) $row->create];
        }
        $after = [];
        foreach ($want as $d => $f) if ($f['view']) $after[$d] = ['reply' => $f['reply'], 'create' => $f['create']];
        ksort($before); ksort($after);
        if ($before !== $after) {
            AuditLog::record($a['domain'], 'admin', null, $a['display_name'] ?? $a['username'],
                'agent.grants-changed', ['ext' => $ext, 'numbers' => $saved, 'permissions' => $want], $request->ip());
        }

        return response()->json(['ok' => true, 'numbers' => $saved, 'grants' => $want]);
    }
}
