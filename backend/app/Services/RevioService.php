<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

/**
 * Rev.io REST API v1 client.
 * Auth: HTTP Basic where the username is "{apiUser}@{clientCode}".
 * All methods return [httpStatus, decodedBody] and throw only when
 * Rev.io can't be reached at all (DNS/timeout/TLS).
 */
class RevioService
{
    public const BASE_URL = 'https://restapi.rev.io/v1';

    public static function basicToken(string $username, string $clientCode, string $password): string
    {
        return base64_encode("{$username}@{$clientCode}:{$password}");
    }

    /** Seconds a transport failure keeps the breaker open. */
    public const BREAKER_SECONDS = 60;
    protected const BREAKER_KEY = 'revio:down';

    protected function checkBreaker(): void
    {
        if (Cache::has(self::BREAKER_KEY)) {
            throw new \RuntimeException('Rev.io unreachable (circuit breaker open).');
        }
    }

    protected function tripBreaker(): void
    {
        Cache::put(self::BREAKER_KEY, 1, now()->addSeconds(self::BREAKER_SECONDS));
    }

    protected function get(string $path, string $username, string $clientCode, string $password): array
    {
        $this->checkBreaker();
        $req = Http::acceptJson()
            ->withToken(self::basicToken($username, $clientCode, $password), 'Basic')
            ->timeout(15);
        if (!config('services.revio.verify', true)) $req->withoutVerifying();
        try {
            $res = $req->get(self::BASE_URL . $path);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->tripBreaker();
            throw $e;
        }
        Cache::forget(self::BREAKER_KEY);
        return [$res->status(), $res->json() ?? []];
    }

    protected function post(string $path, string $username, string $clientCode, string $password, array $payload): array
    {
        $this->checkBreaker();
        $req = Http::acceptJson()
            ->withToken(self::basicToken($username, $clientCode, $password), 'Basic')
            ->timeout(15);
        if (!config('services.revio.verify', true)) $req->withoutVerifying();
        try {
            $res = $req->post(self::BASE_URL . $path, $payload);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->tripBreaker();
            throw $e;
        }
        Cache::forget(self::BREAKER_KEY);
        return [$res->status(), $res->json() ?? []];
    }

    /** Credential check — 200 means the credentials are valid. */
    public function systemStatus(string $username, string $clientCode, string $password): array
    {
        return $this->get('/SystemStatus', $username, $clientCode, $password);
    }

    /** GET /Tickets/{id} — 404 when the ticket doesn't exist. */
    public function ticket(string $username, string $clientCode, string $password, string $ticketId): array
    {
        return $this->get('/Tickets/' . rawurlencode($ticketId), $username, $clientCode, $password);
    }

    /** GET /Customers/{id} — 404 when the account doesn't exist. */
    public function customer(string $username, string $clientCode, string $password, string $customerId): array
    {
        return $this->get('/Customers/' . rawurlencode($customerId), $username, $clientCode, $password);
    }

    /** POST /TicketJournals — note a ticket (e.g. an SMS status check). */
    public function ticketJournal(string $username, string $clientCode, string $password, array $payload): array
    {
        return $this->post('/TicketJournals', $username, $clientCode, $password, $payload);
    }

    /** POST /Tickets — create a ticket. */
    public function postTicket(string $username, string $clientCode, string $password, array $payload): array
    {
        return $this->post('/Tickets', $username, $clientCode, $password, $payload);
    }
}
