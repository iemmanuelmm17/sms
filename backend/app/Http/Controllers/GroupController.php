<?php

namespace App\Http\Controllers;

use App\Events\DataChanged;
use App\Http\Controllers\Concerns\ResolvesActor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Groups are stored as JSON files, one folder per domain:
 *   storage/app/groups/{domain}/{group-slug}.json
 *
 * File shape:
 * {
 *   "id": "uuid", "name": "VIP Clients", "domain": "1180.DynaCloud",
 *   "description": "", "members": [ {contact snapshot...}, ... ],
 *   "created_at": "...", "updated_at": "..."
 * }
 * Members are contact snapshots (unique-id, name, company, phones)
 * so group sends work even if the upstream contact changes.
 */
class GroupController extends Controller
{
    use ResolvesActor;
    protected function domain(Request $r): string
    {
        return $this->actor($r)['domain'];
    }

    protected function dir(string $domain): string
    {
        return "groups/" . preg_replace('/[^A-Za-z0-9._-]/', '_', $domain);
    }

    /** GET /api/groups */
    public function index(Request $request)
    {
        $dir = $this->dir($this->domain($request));
        if (!Storage::exists($dir)) return response()->json([]);
        $out = [];
        foreach (Storage::files($dir) as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) !== 'json') continue;
            $data = \App\Services\JsonFileStore::read($file);
            if ($data) $out[] = $data;
        }
        usort($out, fn($a, $b) => strcmp($a['name'] ?? '', $b['name'] ?? ''));
        return response()->json($out);
    }

    /** POST /api/groups { name, description?, company_id?, members: [...] } */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:120',
            'description' => 'sometimes|nullable|string|max:500',
            'company_id'  => 'sometimes|nullable|string|max:50',
            'members'     => 'required|array|min:1',
            'members.*.unique-id'       => 'sometimes|string',
            'members.*.name-first-name' => 'sometimes|string',
            'members.*.name-last-name'  => 'sometimes|string',
            'members.*.company'         => 'sometimes|nullable|string',
            'members.*.phone'           => 'required|string',  // resolved send-to number
        ]);

        $domain = $this->domain($request);
        $user = $this->actor($request)['user'];
        $this->assertCompanyExists($domain, $data['company_id'] ?? null);
        $group = [
            'id'          => (string) Str::uuid(),
            'name'        => $data['name'],
            'domain'      => $domain,
            'description' => $data['description'] ?? '',
            'company_id'  => $data['company_id'] ?? null,
            'members'     => $data['members'],
            'created_at'  => now()->toISOString(),
            'updated_at'  => now()->toISOString(),
            'created_by' => $user, 'created_by_name' => $this->actor($request)['display_name'],
            'updated_by' => $user, 'updated_by_name' => $this->actor($request)['display_name'],
        ];
        \App\Services\JsonFileStore::put($this->dir($domain) . "/{$group['id']}.json", $group);
        DataChanged::send($domain, $user, 'groups', 'saved', $group['id']);
        return response()->json($group, 201);
    }

    /** GET /api/groups/{id} */
    public function show(Request $request, string $id)
    {
        return response()->json($this->load($this->domain($request), $id));
    }

    /** PUT /api/groups/{id} */
    public function update(Request $request, string $id)
    {
        $domain = $this->domain($request);
        $user = $this->actor($request)['user'];
        $group = $this->load($domain, $id);
        $data = $request->validate([
            'name'        => 'sometimes|string|max:120',
            'description' => 'sometimes|nullable|string|max:500',
            'company_id'  => 'sometimes|nullable|string|max:50',
            'members'     => 'sometimes|array|min:1',
        ]);
        if (array_key_exists('company_id', $data)) $this->assertCompanyExists($domain, $data['company_id']);
        $byName = $this->actor($request)['display_name'];
        $file = $this->dir($domain) . "/{$id}.json";
        $group = \App\Services\JsonFileStore::mutate($file, function ($fresh) use ($data, $user, $byName) {
            return array_merge(is_array($fresh) ? $fresh : [], $data, ['updated_at' => now()->toISOString(),
                'updated_by' => $user, 'updated_by_name' => $byName]);
        }, true);
        abort_unless($group !== null, 404, 'Group not found');
        DataChanged::send($domain, $user, 'groups', 'saved', $id);
        return response()->json($group);
    }

    /** DELETE /api/groups/{id} */
    public function destroy(Request $request, string $id)
    {
        $this->requireAdmin($request);
        $domain = $this->domain($request);
        $user = $this->actor($request)['user'];
        $this->assertUuid($id); // traversal defense: destroy skips load()
        \App\Services\JsonFileStore::delete($this->dir($domain) . "/{$id}.json");
        DataChanged::send($domain, $user, 'groups', 'deleted', $id);
        return response()->json(['ok' => true]);
    }

    protected function load(string $domain, string $id): array
    {
        $this->assertUuid($id); // traversal defense: {id} reaches the filesystem
        $file = $this->dir($domain) . "/{$id}.json";
        $data = \App\Services\JsonFileStore::read($file);
        abort_unless(is_array($data) && $data !== [], 404, 'Group not found');
        return $data;
    }

    /** Groups may stand alone, but a linked company_id must exist. */
    protected function assertCompanyExists(string $domain, mixed $companyId): void
    {
        if ($companyId === null || $companyId === '') return;
        abort_unless(self::isUuid($companyId), 422, 'Company not found');
        $file = 'companies/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $domain) . "/{$companyId}.json";
        abort_unless(Storage::exists($file), 422, 'Company not found');
    }
}
