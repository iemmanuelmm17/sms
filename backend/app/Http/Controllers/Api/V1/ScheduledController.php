<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ScheduledMessageController;
use App\Models\ScheduledMessage;
use Illuminate\Http\Request;

/** v1 tenant API — read scheduled messages + their delivery summaries. */
class ScheduledController extends Controller
{
    /** GET /api/v1/scheduled */
    public function index(Request $request)
    {
        $admin = $request->user();
        $tenant = $admin?->tenant;
        if (!$tenant || !$tenant->isActive()) {
            return response()->json(['message' => 'Tenant inactive.'], 403);
        }
        $items = ScheduledMessage::where('domain', $tenant->domain)->where('user', $tenant->dynalink_user)
            ->orderByDesc('send_at')->limit(100)->get();
        return response()->json($items->map(fn($m) => ScheduledMessageController::reportFor($m, false))->values());
    }

    /** GET /api/v1/scheduled/{id} — full per-recipient report. */
    public function show(Request $request, ScheduledMessage $scheduled)
    {
        $admin = $request->user();
        $tenant = $admin?->tenant;
        if (!$tenant || $scheduled->domain !== $tenant->domain || $scheduled->user !== $tenant->dynalink_user) {
            return response()->json(['message' => 'Not found.'], 404);
        }
        return response()->json(ScheduledMessageController::reportFor($scheduled, true));
    }
}
