<?php

namespace App\Http\Controllers;

use App\Events\DataChanged;
use App\Services\OptOutService;
use App\Http\Controllers\Concerns\ResolvesActor;
use Illuminate\Http\Request;

/** TCPA do-not-contact list (JSON-backed, per domain). */
class OptOutController extends Controller
{
    use ResolvesActor;
    public function __construct(protected OptOutService $optouts) {}

    protected function sess(Request $r): array
    {
        // Actor-shaped session (domain/user/display_name/role). No bearer here —
        // Dynalink call sites use $this->dtoken(request()) so local-only reads
        // never touch the provider.
        return $this->actor($r);
    }

    /** GET /api/opt-outs */
    public function index(Request $request)
    {
        $s = $this->sess($request);
        $list = $this->optouts->all($s['domain']);
        $out = [];
        foreach ($list as $phone => $meta) {
            $out[] = ['phone' => (string) $phone] + (is_array($meta) ? $meta : []) + ['numbers' => ['*']];
        }
        usort($out, fn($a, $b) => strcmp($b['at'] ?? '', $a['at'] ?? ''));
        return response()->json($out);
    }

    /** POST /api/opt-outs { phone, note? } */
    public function store(Request $request)
    {
        $s = $this->sess($request);
        $data = $request->validate([
            'phone'  => 'required|string|max:30',
            'note'   => 'sometimes|nullable|string|max:200',
            'number' => 'sometimes|nullable|string|max:30',
        ]);
        if (strlen(OptOutService::digits($data['phone'])) < 10) {
            return response()->json(['message' => 'Phone number needs at least 10 digits.'], 422);
        }
        $added = $this->optouts->optOut($s['domain'], $data['phone'], 'manual', $data['note'] ?? null, $data['number'] ?? null);
        if ($added) $this->audit($request, 'opt-out.added', array_filter(['phone' => OptOutService::digits($data['phone']), 'number' => isset($data['number']) ? OptOutService::digits($data['number']) : null, 'note' => $data['note'] ?? null]));
        DataChanged::send($s['domain'], $s['user'], 'optouts', 'saved');
        DataChanged::send($s['domain'], $s['user'], 'opt-events', 'saved');
        return response()->json(['ok' => true, 'added' => $added], $added ? 201 : 200);
    }

    /** DELETE /api/opt-outs/{phone} — removal is admin-only (agents may still add). */
    public function destroy(Request $request, string $phone)
    {
        $s = $this->sess($request);
        $this->requireAdmin($s);
        $removed = $this->optouts->remove($s['domain'], $phone);
        if ($removed) $this->audit($request, 'opt-out.removed', ['phone' => OptOutService::digits($phone)]);
        DataChanged::send($s['domain'], $s['user'], 'optouts', 'saved');
        DataChanged::send($s['domain'], $s['user'], 'opt-events', 'saved');
        return response()->json(['ok' => true, 'removed' => $removed]);
    }
}
