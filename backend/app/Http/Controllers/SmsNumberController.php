<?php

namespace App\Http\Controllers;

use App\Services\AgentAccess;
use App\Services\CompanySettingsService;
use App\Services\DynalinkService;
use App\Http\Controllers\Concerns\ResolvesActor;
use Illuminate\Http\Request;

class SmsNumberController extends Controller
{
    use ResolvesActor;
    public function __construct(
        protected DynalinkService $dynalink,
        protected AgentAccess $access,
        protected CompanySettingsService $settings,
    ) {}

    /** GET /api/sms-numbers — numbers assigned to this user (from-number choices). */
    public function index(Request $request)
    {
        $a = $this->actor($request);

        // Base provider list (own numbers for the calling identity).
        $ownRows = $this->dynalink->smsNumbers($this->dtoken($request), $a['domain'], $a['user']);

        // Portal-authenticated agents: include granted SHARED numbers (view/reply/create)
        // so dropdowns for reply AND create-new actually show the lines they were
        // granted. Without this, api.smsNumbers() only returns the provider's
        // own-number list and shared lines are invisible in the UI.
        if (($a['role'] ?? '') === 'agent' && !empty($a['portal_auth'])) {
            try {
                $ext = $a['ext'] ?? $a['user'] ?? '';
                $readable = $this->access->readableNumbers($a['domain'], $ext, $this->dtoken($request));
                if (!empty($readable)) {
                    $domainRows = $this->dynalink->domainSmsNumbers($this->dtoken($request), $a['domain']);
                    $byDigits = [];
                    foreach ($domainRows as $row) {
                        if (!is_array($row)) continue;
                        $n = DynalinkService::shapeNumber($row);
                        if ($n['digits'] !== '') $byDigits[$n['digits']] = $n;
                    }
                    $seen = [];
                    $out = [];
                    foreach ($ownRows as $row) {
                        if (!is_array($row)) continue;
                        $n = DynalinkService::shapeNumber($row);
                        if ($n['digits'] === '') continue;
                        $seen[$n['digits']] = true;
                        $out[] = $byDigits[$n['digits']] ?? $n;
                    }
                    foreach ($readable as $d) {
                        if (isset($seen[$d])) continue;
                        $seen[$d] = true;
                        $out[] = $byDigits[$d] ?? [
                            'number' => $d,
                            'digits' => $d,
                            'dest' => null,
                            'mms_capable' => false,
                            'group_mms_capable' => false,
                            'application' => null,
                            'carrier' => null,
                            'domain' => $a['domain'],
                        ];
                    }
                    usort($out, fn($x, $y) => strcmp($x['digits'] ?? '', $y['digits'] ?? ''));
                    return response()->json($out);
                }
            } catch (\Throwable $e) {
                // Fall back to provider list — never break the dropdown.
                \Illuminate\Support\Facades\Log::warning('smsNumbers: readable merge failed', ['error' => $e->getMessage()]);
            }
        }

        // Legacy local agents (pre-portal): return what they have assigned.
        if (($a['role'] ?? '') === 'agent' && !empty($a['agent_id'])) {
            try {
                $agent = \App\Models\Agent::find($a['agent_id']);
                if ($agent) {
                    $assigned = $agent->assignedNumbers();
                    if (!empty($assigned)) {
                        $byDigits = [];
                        foreach ($ownRows as $row) {
                            if (!is_array($row)) continue;
                            $n = DynalinkService::shapeNumber($row);
                            if ($n['digits'] !== '') $byDigits[$n['digits']] = $n;
                        }
                        $out = [];
                        foreach ($assigned as $d) {
                            if (isset($byDigits[$d])) $out[] = $byDigits[$d];
                            else $out[] = ['number' => $d, 'digits' => $d, 'dest' => null, 'mms_capable' => false, 'group_mms_capable' => false];
                        }
                        usort($out, fn($x,$y)=>strcmp($x['digits'] ?? '', $y['digits'] ?? ''));
                        return response()->json($out);
                    }
                }
            } catch (\Throwable $e) {}
        }

        return response()->json($ownRows);
    }

    /**
     * GET /api/domain-sms-numbers — every SMS number on the admin's domain.
     *
     * Backs the admin Numbers page. Agents are refused: the domain inventory
     * includes numbers they are not assigned, so exposing it would leak the
     * account's full footprint to a non-admin.
     */
    public function domainIndex(Request $request)
    {
        $a = $this->actor($request);
        abort_unless($a['role'] === 'admin', 403, 'Admins only.');

        $rows = $this->dynalink->domainSmsNumbers($this->dtoken($request), $a['domain']);
        $shared = app(\App\Services\CompanySettingsService::class)->sharedNumbers($a['domain']);

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $n = DynalinkService::shapeNumber($row);
            if ($n['digits'] === '') continue;          // unusable without a number
            $n['shared'] = in_array($n['digits'], $shared, true);
            $out[] = $n;
        }
        usort($out, fn($x, $y) => strcmp($x['digits'], $y['digits']));

        return response()->json(['numbers' => $out, 'domain' => $a['domain']]);
    }
}
