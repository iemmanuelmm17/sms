<?php

namespace App\Http\Controllers;

use App\Services\DynalinkService;
use App\Http\Controllers\Concerns\ResolvesActor;
use Illuminate\Http\Request;

class SmsNumberController extends Controller
{
    use ResolvesActor;
    public function __construct(protected DynalinkService $dynalink) {}

    /** GET /api/sms-numbers — numbers assigned to this user (from-number choices). */
    public function index(Request $request)
    {
        $a = $this->actor($request);
        return response()->json($this->dynalink->smsNumbers($this->dtoken(request()), $a['domain'], $a['user']));
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
