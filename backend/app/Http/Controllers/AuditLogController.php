<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Http\Controllers\Concerns\ResolvesActor;
use Illuminate\Http\Request;

/** Append-only audit trail reader (phase 3). Admin only, domain-scoped. */
class AuditLogController extends Controller
{
    use ResolvesActor;

    /** GET /api/audit-logs — filters: ?action=&actor=&from=&to=&per_page= (max 500). */
    public function index(Request $request)
    {
        $this->requireAdmin($request);
        $actor = $this->actor($request);
        // Domain admins see their own domain only — never other domains, and never
        // superadmin/console activity (the superadmin portal has its own viewer).
        $q = AuditLog::where('domain', $actor['domain'])
            ->whereNotIn('actor_type', ['superadmin', 'console'])
            ->orderByDesc('id');
        if ($request->filled('action')) $q->where('action', $request->query('action'));
        if ($request->filled('actor')) {
            $like = '%' . $request->query('actor') . '%';
            $q->where(fn($w) => $w->where('actor_name', 'like', $like)->orWhere('actor_type', 'like', $like));
        }
        if ($request->filled('from')) $q->where('created_at', '>=', $request->query('from'));
        if ($request->filled('to')) {
            $to = $request->query('to');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to .= ' 23:59:59';
            $q->where('created_at', '<=', $to);
        }
        $per = min(max((int) $request->query('per_page', 50), 1), 500);
        return response()->json($q->paginate($per));
    }

    /** GET /api/audit-logs/actions — distinct action names for the filter dropdown. */
    public function actions(Request $request)
    {
        $this->requireAdmin($request);
        $actor = $this->actor($request);
        return response()->json(
            AuditLog::where('domain', $actor['domain'])->whereNotIn('actor_type', ['superadmin', 'console'])->distinct()->orderBy('action')->pluck('action')
        );
    }
}
