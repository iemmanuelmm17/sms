<?php

namespace App\Http\Controllers;

use App\Events\DataChanged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Models\Contact;
use App\Services\ContactSyncService;
use App\Services\DynalinkService;
use App\Http\Controllers\Concerns\ResolvesActor;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    use ResolvesActor;
    public function __construct(protected DynalinkService $dynalink, protected ContactSyncService $sync) {}

    protected function sess(Request $r): array
    {
        // Actor-shaped session (domain/user/display_name/role). No bearer here —
        // Dynalink call sites use $this->dtoken(request()) so local-only reads
        // never touch the provider.
        return $this->actor($r);
    }

    /** Any provided phone must contain at least 10 digits (no extensions). */
    protected function assertValidPhones(array $data): void
    {
        foreach (['phonenumber-work', 'phonenumber-cell', 'phonenumber-home', 'phonenumber-fax'] as $k) {
            $v = trim((string) ($data[$k] ?? ''));
            if ($v !== '' && strlen(preg_replace('/\D/', '', $v)) < 10) {
                abort(response()->json(
                    ['message' => "Invalid phone number in {$k} — at least 10 digits required (extensions can't receive SMS)."],
                    422
                ));
            }
        }
    }

    /**
     * GET /api/contacts — reads the LOCAL table (always a JSON list).
     * The provider is only touched when the local table is still empty:
     * one backfill, then never again until someone presses Resync or the
     * nightly job runs. That keeps every page load local and fast.
     */
    public function index(Request $request)
    {
        $s = $this->sess($request);
        $rows = $this->sync->localList($s['domain'], $s['user']);
        if ($rows->isEmpty() && $this->maybeBackfill($s)) {
            $rows = $this->sync->localList($s['domain'], $s['user']);
        }
        return response()->json($rows->map(fn($c) => $c->toProviderArray())->values());
    }

    /**
     * First-read backfill: pull the address book once so a fresh install never
     * shows an empty list. Guarded by a 12h cache flag so a tenant with
     * genuinely zero contacts doesn't re-hit the provider on every load.
     * Returns true when a sync actually ran.
     */
    protected function maybeBackfill(array $s): bool
    {
        $key  = "contacts:backfill:{$s['domain']}:{$s['user']}";
        if (Cache::has($key)) return false;
        $lock = Cache::lock($key . ':lock', 60);
        try {
            $lock->get();
            $this->sync->sync($this->dtoken(request()), $s['domain'], $s['user']);
            Log::info('Contacts: initial backfill from provider', ['domain' => $s['domain'], 'user' => $s['user']]);
        } catch (\Throwable $e) {
            Log::warning('Contacts: backfill failed — ' . $e->getMessage());
        } finally {
            Cache::put($key, now()->toISOString(), now()->addHours(12));
            try { $lock->release(); } catch (\Throwable $e) {}
        }
        return true;
    }

    /** POST /api/contacts/resync — two-way sync with the portal (admin only). */
    public function resync(Request $request)
    {
        $s = $this->sess($request);
        $this->requireAdmin($request);
        $res = $this->sync->sync($this->dtoken(request()), $s['domain'], $s['user']);
        $res['last_synced_at'] = $this->lastSyncedAt($s['domain'], $s['user']);
        if ($res['created'] || $res['updated'] || $res['removed'] || $res['pushed']) {
            DataChanged::send($s['domain'], $s['user'], 'contacts', 'saved');
        }
        return response()->json($res);
    }

    /** GET /api/contacts/sync-status — local count + last sync time. */
    public function status(Request $request)
    {
        $s = $this->sess($request);
        $q = Contact::where('domain', $s['domain'])->where('user', $s['user']);
        return response()->json([
            'count'          => (int) (clone $q)->count(),
            'last_synced_at' => $this->lastSyncedAt($s['domain'], $s['user']),
        ]);
    }

    protected function lastSyncedAt(string $domain, string $user): ?string
    {
        $max = Contact::where('domain', $domain)->where('user', $user)->max('synced_at');
        if (!$max) return null;
        try { return \Illuminate\Support\Carbon::parse($max)->toISOString(); } catch (\Throwable $e) { return (string) $max; }
    }

    /**
     * Dynalink create/update replies are inconsistent: an array (sometimes
     * wrapped in a list), or a bare id string. → [row, providerId].
     */
    protected function providerRow(mixed $body): array
    {
        if (is_string($body)) {
            $t = trim($body);
            return [[], $t !== '' && strlen($t) <= 120 && preg_match('/^[A-Za-z0-9._@-]+$/', $t) ? $t : ''];
        }
        if (!is_array($body)) return [[], ''];
        $row = isset($body[0]) && is_array($body[0]) ? $body[0] : $body;
        if (!is_array($row)) return [[], ''];
        return [$row, ContactSyncService::providerIdOf($row)];
    }

    /** POST /api/contacts — First name + Last name + Cellphone are required. */
    public function store(Request $request)
    {
        $s = $this->sess($request);
        $data = $request->validate([
            'name-first-name'   => 'required|string|max:100',
            'name-middle-name'  => 'sometimes|nullable|string|max:100',
            'name-last-name'    => 'required|string|max:100',
            'email'             => 'sometimes|nullable|string|max:255',
            'company'           => 'sometimes|nullable|string|max:255',
            'phonenumber-work'  => 'sometimes|nullable|string|max:30',
            'phonenumber-cell'  => 'required|string|max:30',
            'phonenumber-home'  => 'sometimes|nullable|string|max:30',
            'phonenumber-fax'   => 'sometimes|nullable|string|max:30',
        ]);
        $this->assertValidPhones($data);
        [$status, $body] = $this->dynalink->createContact($this->dtoken(request()), $s['domain'], $s['user'], $data);
        if ($status >= 200 && $status < 300) {
            [$row, $pid] = $this->providerRow($body);
            $this->sync->upsertFromWrite($s['domain'], $s['user'], array_merge($data, $row), $pid);
            DataChanged::send($s['domain'], $s['user'], 'contacts', 'saved');
        }
        return response()->json($body, $status);
    }

    /** PUT /api/contacts/{id} */
    public function update(Request $request, string $id)
    {
        $s = $this->sess($request);
        $data = $request->validate([
            'name-first-name'   => 'sometimes|string|max:100',
            'name-middle-name'  => 'sometimes|nullable|string|max:100',
            'name-last-name'    => 'sometimes|string|max:100',
            'email'             => 'sometimes|nullable|string|max:255',
            'company'           => 'sometimes|nullable|string|max:255',
            'phonenumber-work'  => 'sometimes|nullable|string|max:30',
            'phonenumber-cell'  => 'sometimes|nullable|string|max:30',
            'phonenumber-home'  => 'sometimes|nullable|string|max:30',
            'phonenumber-fax'   => 'sometimes|nullable|string|max:30',
        ]);
        $this->assertValidPhones($data);
        [$status, $body] = $this->dynalink->updateContact($this->dtoken(request()), $s['domain'], $s['user'], $id, $data);
        if ($status >= 200 && $status < 300) {
            [$row] = $this->providerRow($body);
            $this->sync->upsertFromWrite($s['domain'], $s['user'], array_merge($data, $row), $id);
            DataChanged::send($s['domain'], $s['user'], 'contacts', 'saved', $id);
        }
        return response()->json($body, $status);
    }

    /** DELETE /api/contacts/{id} — admin-only (agents may still add/update). */
    public function destroy(Request $request, string $id)
    {
        $s = $this->sess($request);
        $this->requireAdmin($s);
        [$status, $body] = $this->dynalink->deleteContact($this->dtoken(request()), $s['domain'], $s['user'], $id);
        if ($status >= 200 && $status < 300) {
            $this->sync->forget($s['domain'], $s['user'], $id);
            DataChanged::send($s['domain'], $s['user'], 'contacts', 'deleted', $id);
        }
        return response()->json($body, $status);
    }

    /** GET /api/contacts/template — downloadable CSV template. */
    public function template()
    {
        $csv = "first_name,middle_name,last_name,email,company,phone_work,phone_cell,phone_home,phone_fax\n" .
               "John,,Doe,john@example.com,Acme Inc,,19175551212,,\n";
        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="contacts_template.csv"',
        ]);
    }

    /**
     * POST /api/contacts/import — multipart `file` (.csv).
     * Robust to header case differences; row-level error feedback.
     * Requires first_name + last_name + phone_cell per row; every provided
     * phone must have 10+ digits. Creates 1-by-1 via Dynalink API.
     */
    public function import(Request $request)
    {
        $s = $this->sess($request);
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:5120']);

        $path = $request->file('file')->getRealPath();
        $rows = array_map('str_getcsv', file($path));
        if (empty($rows)) {
            return response()->json(['message' => 'Empty CSV file.'], 422);
        }

        $headers = array_map(fn($h) => strtolower(trim($h)), array_shift($rows));
        // Accept aliases
        $alias = [
            'firstname' => 'first_name', 'first name' => 'first_name', 'name-first-name' => 'first_name',
            'lastname' => 'last_name', 'last name' => 'last_name', 'name-last-name' => 'last_name',
            'middlename' => 'middle_name', 'middle name' => 'middle_name',
            'phone' => 'phone_cell', 'cell' => 'phone_cell', 'mobile' => 'phone_cell', 'cellphone' => 'phone_cell',
            'phonenumber-cell' => 'phone_cell', 'phonenumber-work' => 'phone_work',
            'phonenumber-home' => 'phone_home', 'phonenumber-fax' => 'phone_fax',
        ];
        $headers = array_map(fn($h) => $alias[$h] ?? str_replace([' ', '-'], '_', $h), $headers);

        // Row cap: every row is a synchronous Dynalink call, so huge files
        // die halfway under PHP's time limit. Ask the user to split instead.
        $nonBlank = 0;
        foreach ($rows as $r) {
            if (count(array_filter($r, fn($v) => trim((string) $v) !== '')) > 0) $nonBlank++;
        }
        if ($nonBlank > 200) {
            return response()->json(['message' => 'File too big — split it into files of 200 contacts or fewer and import each one.'], 422);
        }
        if (function_exists('set_time_limit')) set_time_limit(300);

        $created = 0; $errors = [];
        foreach ($rows as $i => $row) {
            $line = $i + 2;
            if (count(array_filter($row, fn($v) => trim((string) $v) !== '')) === 0) continue;
            $rec = @array_combine($headers, array_pad($row, count($headers), ''));
            if ($rec === false) { $errors[] = ['row' => $line, 'error' => 'Column mismatch']; continue; }
            $rec = array_map('trim', $rec);

            if (empty($rec['first_name'] ?? '')) { $errors[] = ['row' => $line, 'error' => 'First name is required']; continue; }
            if (empty($rec['last_name'] ?? '')) { $errors[] = ['row' => $line, 'error' => 'Last name is required']; continue; }
            if (empty($rec['phone_cell'] ?? '')) { $errors[] = ['row' => $line, 'error' => 'Cellphone (phone_cell) is required']; continue; }

            $badPhone = null;
            foreach (['phone_work' => 'phone_work', 'phone_cell' => 'phone_cell', 'phone_home' => 'phone_home', 'phone_fax' => 'phone_fax'] as $col) {
                $v = $rec[$col] ?? '';
                if ($v !== '' && strlen(preg_replace('/\D/', '', $v)) < 10) { $badPhone = $col; break; }
            }
            if ($badPhone) { $errors[] = ['row' => $line, 'error' => "Invalid phone in {$badPhone} — at least 10 digits required"]; continue; }

            $payload = array_filter([
                'name-first-name'   => $rec['first_name']  ?? '',
                'name-middle-name'  => $rec['middle_name'] ?? '',
                'name-last-name'    => $rec['last_name']   ?? '',
                'email'             => $rec['email']       ?? '',
                'company'           => $rec['company']     ?? '',
                'phonenumber-work'  => $rec['phone_work']  ?? '',
                'phonenumber-cell'  => $rec['phone_cell']  ?? '',
                'phonenumber-home'  => $rec['phone_home']  ?? '',
                'phonenumber-fax'   => $rec['phone_fax']   ?? '',
            ], fn($v) => $v !== '');

            [$status, $body] = $this->dynalink->createContact($this->dtoken(request()), $s['domain'], $s['user'], $payload);
            if ($status >= 200 && $status < 300) {
                [$row, $pid] = $this->providerRow($body);
                $this->sync->upsertFromWrite($s['domain'], $s['user'], array_merge($payload, $row), $pid);
                $created++;
            } else {
                $errors[] = ['row' => $line, 'error' => is_string($body) ? $body : json_encode($body)];
            }
        }

        if ($created > 0) DataChanged::send($s['domain'], $s['user'], 'contacts', 'saved');
        return response()->json(['created' => $created, 'failed' => count($errors), 'errors' => $errors]);
    }
}
