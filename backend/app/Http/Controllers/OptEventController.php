<?php

namespace App\Http\Controllers;

use App\Models\OptEvent;
use App\Services\OptOutService;
use App\Http\Controllers\Concerns\ResolvesActor;
use Illuminate\Http\Request;

/**
 * GET /api/opt-events?direction=opt_in|opt_out — latest event per phone.
 * A phone appears under a direction only if its LATEST event has that
 * direction (STOP then START shows under opt-in, not opt-out).
 * Legacy JSON opt-outs with no event row are backfilled on first read.
 */
class OptEventController extends Controller
{
    use ResolvesActor;
    public function __construct(protected OptOutService $optouts) {}

    public function index(Request $request)
    {
        $domain = $this->actor($request)['domain'];
        $direction = $request->input('direction');

        $this->backfill($domain);

        $events = OptEvent::where('domain', $domain)
            ->orderByDesc('occurred_at')->orderByDesc('id')->get();

        $seen = [];
        $out = [];
        foreach ($events as $e) {
            $d = preg_replace('/\D/', '', (string) $e->phone_number);
            $alts = [$d];
            if (strlen($d) === 11 && str_starts_with($d, '1')) $alts[] = substr($d, 1);
            if (strlen($d) === 10) $alts[] = '1' . $d;
            $dup = false;
            foreach ($alts as $a) { if (isset($seen[$a])) { $dup = true; break; } }
            if ($dup) continue;
            foreach ($alts as $a) $seen[$a] = true;
            if ($direction && $e->direction !== $direction) continue;
            $out[] = $e;
        }
        return response()->json(array_values($out));
    }

    /** One-time history rows for pre-existing JSON opt-outs. */
    protected function backfill(string $domain): void
    {
        foreach ($this->optouts->all($domain) as $phone => $meta) {
            $d = preg_replace('/\D/', '', (string) $phone);
            if ($d === '' || OptEvent::where('domain', $domain)->where('phone_number', $d)->exists()) continue;
            OptEvent::create([
                'domain' => $domain, 'phone_number' => $d, 'contact_id' => null,
                'direction' => 'opt_out',
                'keyword' => is_array($meta) ? ($meta['note'] ?? 'manual') : 'manual',
                'occurred_at' => is_array($meta) && !empty($meta['at']) ? $meta['at'] : now(),
            ]);
        }
    }
}
