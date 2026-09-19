<?php

namespace App\Http\Controllers;

use App\Events\DataChanged;
use App\Services\DynalinkService;
use App\Http\Controllers\Concerns\ResolvesActor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Companies are stored as JSON files, one folder per domain:
 *   storage/app/companies/{domain}/{company-id}.json
 *
 * File shape:
 * {
 *   "id": "uuid", "name": "Acme", "domain": "1180.DynaCloud",
 *   "address": "", "note": "", "numbers": ["+...", ...],
 *   "created_at": "...", "updated_at": "..."
 * }
 *
 * A company CONTAINS, by convention (nothing is copied):
 *  - contacts: Dynalink contacts whose `company` field equals the company name
 *  - numbers:  the manual/SMS number list stored on the company
 *  - groups:   groups whose `company_id` equals the company id
 *
 * Delete is blocked (422) while contacts or groups are attached; rename is
 * blocked while contacts use the name (contacts reference it by text).
 */
class CompanyController extends Controller
{
    use ResolvesActor;
    public function __construct(protected DynalinkService $dynalink) {}

    protected function sess(Request $r): array
    {
        // Actor-shaped session (domain/user/display_name/role). No bearer here —
        // Dynalink call sites use $this->dtoken(request()) so local-only reads
        // never touch the provider.
        return $this->actor($r);
    }

    protected function dir(string $domain): string
    {
        return 'companies/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $domain);
    }

    protected function groupDir(string $domain): string
    {
        return 'groups/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $domain);
    }

    protected function all(string $domain): array
    {
        $dir = $this->dir($domain);
        if (! Storage::exists($dir)) return [];
        $out = [];
        foreach (Storage::files($dir) as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) !== 'json') continue;
            $data = json_decode(Storage::get($file), true);
            if ($data) $out[] = $data;
        }
        usort($out, fn ($a, $b) => strcmp($a['name'] ?? '', $b['name'] ?? ''));
        return $out;
    }

    protected function load(string $domain, string $id): array
    {
        $this->assertUuid($id); // traversal defense: {id} reaches the filesystem
        $file = $this->dir($domain) . "/{$id}.json";
        abort_unless(Storage::exists($file), 404, 'Company not found');
        return json_decode(Storage::get($file), true);
    }

    /** [contactCount, groupCount] currently attached to this company. */
    protected function attachments(array $s, array $company): array
    {
        $name = strtolower(trim($company['name'] ?? ''));
        $contactCount = 0;
        if ($name !== '') {
            $contacts = $this->dynalink->contacts($this->dtoken(request()), $s['domain'], $s['user']);
            if (is_array($contacts)) {
                foreach ($contacts as $c) {
                    if (! is_array($c)) continue;
                    if (strtolower(trim($c['company'] ?? '')) === $name) $contactCount++;
                }
            }
        }
        $groupCount = 0;
        $gdir = $this->groupDir($s['domain']);
        if (Storage::exists($gdir)) {
            foreach (Storage::files($gdir) as $file) {
                if (pathinfo($file, PATHINFO_EXTENSION) !== 'json') continue;
                $g = json_decode(Storage::get($file), true);
                if (($g['company_id'] ?? '') === $company['id']) $groupCount++;
            }
        }
        return [$contactCount, $groupCount];
    }

    protected function assertValidNumbers(array $numbers): void
    {
        foreach ($numbers as $n) {
            if (strlen(preg_replace('/\D/', '', (string) $n)) < 7) {
                abort(response()->json(
                    ['message' => "Invalid number \"{$n}\" — at least 7 digits required."],
                    422
                ));
            }
        }
    }

    /** GET /api/companies */
    public function index(Request $request)
    {
        $s = $this->sess($request);
        return response()->json($this->all($s['domain']));
    }

    /** POST /api/companies { name, address?, note?, numbers? } */
    public function store(Request $request)
    {
        $s = $this->sess($request);
        $data = $request->validate([
            'name'      => 'required|string|max:120',
            'address'   => 'sometimes|nullable|string|max:500',
            'note'      => 'sometimes|nullable|string|max:2000',
            'numbers'   => 'sometimes|array',
            'numbers.*' => 'string|max:30',
        ]);
        $this->assertValidNumbers($data['numbers'] ?? []);

        $name = trim($data['name']);
        foreach ($this->all($s['domain']) as $c) {
            if (strtolower(trim($c['name'] ?? '')) === strtolower($name)) {
                abort(response()->json(['message' => 'A company with this name already exists.'], 422));
            }
        }

        $company = [
            'id'         => (string) Str::uuid(),
            'name'       => $name,
            'domain'     => $s['domain'],
            'address'    => $data['address'] ?? '',
            'note'       => $data['note'] ?? '',
            'numbers'    => array_values($data['numbers'] ?? []),
            'created_at' => now()->toISOString(),
            'updated_at' => now()->toISOString(),
            'created_by' => $s['user'] ?? null, 'created_by_name' => $s['display_name'] ?? null,
            'updated_by' => $s['user'] ?? null, 'updated_by_name' => $s['display_name'] ?? null,
        ];
        Storage::put($this->dir($s['domain']) . "/{$company['id']}.json", json_encode($company, JSON_PRETTY_PRINT));
        DataChanged::send($s['domain'], $s['user'], 'companies', 'saved', $company['id']);
        return response()->json($company, 201);
    }

    /** GET /api/companies/{id} */
    public function show(Request $request, string $id)
    {
        $s = $this->sess($request);
        return response()->json($this->load($s['domain'], $id));
    }

    /** PUT /api/companies/{id} */
    public function update(Request $request, string $id)
    {
        $s = $this->sess($request);
        $company = $this->load($s['domain'], $id);
        $data = $request->validate([
            'name'      => 'sometimes|string|max:120',
            'address'   => 'sometimes|nullable|string|max:500',
            'note'      => 'sometimes|nullable|string|max:2000',
            'numbers'   => 'sometimes|array',
            'numbers.*' => 'string|max:30',
        ]);
        if (array_key_exists('numbers', $data)) $this->assertValidNumbers($data['numbers'] ?? []);

        if (isset($data['name']) && strtolower(trim($data['name'])) !== strtolower(trim($company['name'] ?? ''))) {
            foreach ($this->all($s['domain']) as $c) {
                if ($c['id'] !== $id && strtolower(trim($c['name'] ?? '')) === strtolower(trim($data['name']))) {
                    abort(response()->json(['message' => 'A company with this name already exists.'], 422));
                }
            }
            [$contactCount] = $this->attachments($s, $company);
            if ($contactCount > 0) {
                abort(response()->json(['message' => "Cannot rename — {$contactCount} contact(s) are assigned to \"{$company['name']}\". Edit them first."], 422));
            }
            $data['name'] = trim($data['name']);
        }

        $company = array_merge($company, $data, ['updated_at' => now()->toISOString(),
            'updated_by' => $s['user'] ?? null, 'updated_by_name' => $s['display_name'] ?? null]);
        Storage::put($this->dir($s['domain']) . "/{$id}.json", json_encode($company, JSON_PRETTY_PRINT));
        DataChanged::send($s['domain'], $s['user'], 'companies', 'saved', $id);
        return response()->json($company);
    }

    /** DELETE /api/companies/{id} — blocked while contacts or groups attach. */
    public function destroy(Request $request, string $id)
    {
        $s = $this->sess($request);
        $this->requireAdmin($s);
        $company = $this->load($s['domain'], $id);
        [$contactCount, $groupCount] = $this->attachments($s, $company);
        if ($contactCount > 0 || $groupCount > 0) {
            $reasons = [];
            if ($contactCount > 0) $reasons[] = "{$contactCount} contact(s)";
            if ($groupCount > 0) $reasons[] = "{$groupCount} group(s)";
            abort(response()->json(['message' => 'Cannot delete "' . $company['name'] . '" — still assigned: ' . implode(' and ', $reasons) . '.'], 422));
        }
        Storage::delete($this->dir($s['domain']) . "/{$id}.json");
        DataChanged::send($s['domain'], $s['user'], 'companies', 'deleted', $id);
        return response()->json(['ok' => true]);
    }
}
