<?php

namespace App\Services;

use App\Models\Contact;
use Illuminate\Support\Facades\Log;

/**
 * Contacts live in a local table; the Dynalink portal is the source of truth.
 *
 * Rules agreed with the tenant admin:
 *  1. On resync the PROVIDER WINS — portal values overwrite local fields.
 *  2. Local-only rows (created here, or a portal push failed) are PUSHED UP
 *     to the portal rather than deleted.
 *  3. A contact deleted in the portal is DELETED locally.
 *
 * Matching is by portal id first, then by cell digits — so a contact that
 * already exists at the portal (even if we never stored its id) is adopted
 * instead of duplicated.
 */
class ContactSyncService
{
    public function __construct(protected DynalinkService $dynalink) {}

    /** Portal id from a raw provider row. */
    public static function providerIdOf(array $row): string
    {
        foreach (['unique-id', 'uid', 'id'] as $k) {
            if (isset($row[$k]) && (string) $row[$k] !== '') return (string) $row[$k];
        }
        return '';
    }

    public static function digits(?string $v): string
    {
        return preg_replace('/\D/', '', (string) $v) ?? '';
    }

    /** Raw provider row → local column values. */
    protected function mapRemote(array $row): array
    {
        $str = fn($v) => ($v === null || is_array($v)) ? null : trim((string) $v);
        return [
            'first_name'  => $str($row['name-first-name'] ?? null) ?: null,
            'middle_name' => $str($row['name-middle-name'] ?? null) ?: null,
            'last_name'   => $str($row['name-last-name'] ?? null) ?: null,
            'email'       => $str($row['email'] ?? null) ?: null,
            'company'     => $str($row['company'] ?? null) ?: null,
            'phone_work'  => $str($row['phonenumber-work'] ?? null) ?: null,
            'phone_cell'  => $str($row['phonenumber-cell'] ?? null) ?: null,
            'phone_home'  => $str($row['phonenumber-home'] ?? null) ?: null,
            'phone_fax'   => $str($row['phonenumber-fax'] ?? null) ?: null,
            'raw'         => $row,
            'synced_at'   => now(),
        ];
    }

    /** Normalize the provider list (a lone object arrives as one row). */
    protected function remoteRows(mixed $list): array
    {
        if (!is_array($list)) return [];
        if ($list !== [] && (!isset($list[0]) || !is_array($list[0]))) $list = [$list];
        return array_values(array_filter($list, fn($r) => is_array($r)));
    }

    /**
     * Full two-way sync. Never throws — returns a summary for the UI.
     * @return array{created:int,updated:int,removed:int,pushed:int,count:int,errors:array}
     */
    public function sync(string $token, string $domain, string $user): array
    {
        $created = $updated = $removed = $pushed = 0;
        $errors  = [];

        DynalinkService::bustContacts($domain, $user); // ignore the 120s provider cache
        $remote = $this->remoteRows($this->dynalink->contacts($token, $domain, $user));

        /** @var \Illuminate\Support\Collection $locals */
        $locals = Contact::where('domain', $domain)->where('user', $user)->get();
        $byPid  = [];   // provider id => Contact
        $noPid  = [];   // local-only rows, matched by cell digits
        foreach ($locals as $c) {
            if ((string) $c->provider_id !== '') $byPid[(string) $c->provider_id] = $c;
            else $noPid[] = $c;
        }

        $touched = [];  // local ids handled by the remote pass

        // ---- Pass 1: portal → local (provider wins) ----
        foreach ($remote as $row) {
            $pid  = self::providerIdOf($row);
            $cell = self::digits($row['phonenumber-cell'] ?? '');

            $local = ($pid !== '' && isset($byPid[$pid])) ? $byPid[$pid] : null;
            if (!$local && $cell !== '') {
                foreach ($noPid as $i => $cand) {
                    if ($cand->cellDigits() === $cell) {
                        $local = $cand;
                        unset($noPid[$i]);
                        break;
                    }
                }
            }

            $fill = $this->mapRemote($row)
                + ['domain' => $domain, 'user' => $user, 'provider_id' => $pid !== '' ? $pid : null];

            try {
                if ($local) {
                    $local->fill($fill)->save();
                    $touched[] = $local->id;
                    $updated++;
                } else {
                    $c = Contact::create($fill);
                    $touched[] = $c->id;
                    $created++;
                }
            } catch (\Throwable $e) {
                $errors[] = 'Local save failed: ' . $e->getMessage();
            }
        }

        // ---- Pass 2: reconcile what the portal no longer has ----
        foreach ($locals as $c) {
            if (in_array($c->id, $touched, true)) continue;

            if ((string) $c->provider_id === '') {
                // Local-only → push it up to the portal (rule 2).
                $res = $this->pushUp($token, $domain, $user, $c);
                if ($res === true) $pushed++;
                else $errors[] = $res;
                continue;
            }
            // Has an id the portal doesn't return any more → deleted upstream (rule 3).
            try {
                $c->delete();
                $removed++;
            } catch (\Throwable $e) {
                $errors[] = 'Local delete failed: ' . $e->getMessage();
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'removed' => $removed,
            'pushed'  => $pushed,
            'count'   => Contact::where('domain', $domain)->where('user', $user)->count(),
            'errors'  => array_slice($errors, 0, 10),
        ];
    }

    /** Create a local-only contact at the portal. True on success, else a message. */
    protected function pushUp(string $token, string $domain, string $user, Contact $c): bool|string
    {
        if (trim((string) $c->first_name) === '' || trim((string) $c->last_name) === '' || $c->cellDigits() === '') {
            return 'Skipped "' . trim($c->first_name . ' ' . $c->last_name) . '" — needs first name, last name and a cell number before it can be pushed to the portal.';
        }
        try {
            [$status, $body] = $this->dynalink->createContact($token, $domain, $user, $c->toProviderPayload());
        } catch (\Throwable $e) {
            return 'Push to portal failed: ' . $e->getMessage();
        }
        if ($status < 200 || $status >= 300) {
            return 'Push to portal failed (HTTP ' . $status . '): ' . (is_string($body) ? $body : json_encode($body));
        }
        if (is_array($body)) {
            $row  = isset($body[0]) && is_array($body[0]) ? $body[0] : $body;
            $pid  = self::providerIdOf($row);
            if ($pid !== '') $c->provider_id = $pid;
        } elseif (is_string($body) && trim($body) !== '') {
            $c->provider_id = trim($body);
        }
        $c->pushed_at = now();
        $c->synced_at = now();
        $c->save();
        return true;
    }

    /** Provider rows → local list, for the read path (GET /api/contacts). */
    public function localList(string $domain, string $user): \Illuminate\Support\Collection
    {
        return Contact::where('domain', $domain)->where('user', $user)
            ->orderBy('last_name')->orderBy('first_name')->get();
    }

    /**
     * Record a local write that already succeeded at the portal.
     * Matches by provider id, else by cell digits — never duplicates.
     */
    public function upsertFromWrite(string $domain, string $user, array $providerRow, ?string $providerId = null): ?Contact
    {
        try {
            $pid  = $providerId !== null && $providerId !== '' ? $providerId : self::providerIdOf($providerRow);
            $cell = self::digits($providerRow['phonenumber-cell'] ?? '');

            $q = Contact::where('domain', $domain)->where('user', $user);
            $local = null;
            if ($pid !== '') $local = (clone $q)->where('provider_id', $pid)->first();
            if (!$local && $cell !== '') {
                $local = (clone $q)->get()->firstWhere(fn($c) => $c->cellDigits() === $cell);
            }

            // Provider row may only carry the fields we sent — merge so a
            // partial response never blanks a field we already know.
            $known = $local ? $local->only([
                'first_name', 'middle_name', 'last_name', 'email', 'company',
                'phone_work', 'phone_cell', 'phone_home', 'phone_fax',
            ]) : [];
            $mapped = array_filter($this->mapRemote($providerRow), fn($v) => $v !== null && $v !== '');
            $fill = array_merge($known, $mapped, [
                'domain'      => $domain,
                'user'        => $user,
                'provider_id' => $pid !== '' ? $pid : null,
                'raw'         => $providerRow ?: ($local->raw ?? null),
                'synced_at'   => now(),
            ]);

            if ($local) {
                $local->fill($fill)->save();
                return $local;
            }
            return Contact::create($fill);
        } catch (\Throwable $e) {
            Log::warning('Contact local write failed: ' . $e->getMessage());
            return null;
        }
    }

    /** Remove the local copy of a portal delete. */
    public function forget(string $domain, string $user, ?string $providerId): void
    {
        if (!$providerId) return;
        try {
            Contact::where('domain', $domain)->where('user', $user)
                ->where('provider_id', $providerId)->delete();
        } catch (\Throwable $e) {
            Log::warning('Contact local delete failed: ' . $e->getMessage());
        }
    }
}
