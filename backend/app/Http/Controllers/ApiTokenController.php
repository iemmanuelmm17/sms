<?php

namespace App\Http\Controllers;

use App\Models\TenantAdmin;
use App\Http\Controllers\Concerns\ResolvesActor;
use Illuminate\Http\Request;

/** Portal management for v1 tenant API tokens (tenant-admin login only). */
class ApiTokenController extends Controller
{
    use ResolvesActor;

    protected function owner(Request $r): ?TenantAdmin
    {
        $t = $r->session()->get('tenant');
        if (!$t) return null;
        $a = TenantAdmin::with('tenant')->find($t['id'] ?? null);
        return ($a && $a->isActive() && $a->tenant && $a->tenant->isActive()) ? $a : null;
    }

    /** GET /api/api-tokens */
    public function index(Request $request)
    {
        $this->requireAdmin($request);
        $admin = $this->owner($request);
        if (!$admin) return response()->json(['message' => 'API tokens require tenant-admin login.'], 422);
        return response()->json($admin->tokens()->orderByDesc('id')->get()
            ->map(fn($t) => ['id' => $t->id, 'name' => $t->name, 'abilities' => $t->abilities,
                'last_used_at' => $t->last_used_at, 'created_at' => $t->created_at])->values());
    }

    /** POST /api/api-tokens { name } — plaintext returned ONCE. */
    public function store(Request $request)
    {
        $this->requireAdmin($request);
        $admin = $this->owner($request);
        if (!$admin) return response()->json(['message' => 'API tokens require tenant-admin login.'], 422);
        $data = $request->validate(['name' => 'required|string|max:80']);
        $token = $admin->createToken($data['name'], ['v1']);
        $this->audit($request, 'api-token.created', ['name' => $data['name']]);
        return response()->json(['id' => $token->accessToken->id, 'name' => $data['name'],
            'token' => $token->plainTextToken], 201);
    }

    /** DELETE /api/api-tokens/{id} */
    public function destroy(Request $request, int $id)
    {
        $this->requireAdmin($request);
        $admin = $this->owner($request);
        if (!$admin) return response()->json(['message' => 'API tokens require tenant-admin login.'], 422);
        $tok = $admin->tokens()->where('id', $id)->first();
        if (!$tok) return response()->json(['message' => 'Token not found.'], 404);
        $this->audit($request, 'api-token.revoked', ['name' => $tok->name]);
        $tok->delete();
        return response()->json(['ok' => true]);
    }
}
