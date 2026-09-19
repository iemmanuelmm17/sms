<?php

namespace App\Services;

use App\Events\DataChanged;
use App\Models\ConversationMeta;
use App\Models\Integration;
use App\Models\IntegrationSession;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * Rev.io SMS dialog: one-shot BILL / STATUS / TICKET / AGENT commands over a
 * 60-minute sliding session per customer. Every handled message returns
 * exactly one reply text (null only when handed off and silent);
 * API/transport failures degrade to spiels (never raw errors, never silence).
 */
class RevioDialogService
{
    public const SESSION_MINUTES = 60;
    public const STATE_MENU = 'menu';
    public const STATE_AWAIT_TICKET_DESC = 'await_ticket_desc';
    public const STATE_HANDED_OFF = 'handed_off';

    public function __construct(protected RevioService $revio, protected DynalinkService $dynalink) {}

    public function handle(Integration $integration, string $phone, string $text, array $ctx = []): ?string
    {
        $this->prune($integration);
        $session = IntegrationSession::where('integration_id', $integration->id)
            ->where('phone', $phone)->first();
        $fresh = !$session || !$session->expires_at || $session->expires_at->isPast();
        if ($fresh) {
            $session = IntegrationSession::updateOrCreate(
                ['integration_id' => $integration->id, 'phone' => $phone],
                ['state' => self::STATE_MENU]
            );
        }
        $session->update(['last_activity_at' => now(), 'expires_at' => now()->addMinutes(self::SESSION_MINUTES)]);

        [$first, $args] = $this->parseCommand($text);
        if ($first === 'MENU') {
            $this->patchData($session, ['create' => null]);
            $session->update(['state' => self::STATE_MENU]);
            return $this->welcome($integration, $ctx);
        }
        if ($first === 'AGENT') {
            return $this->agentTurn($integration, $session, $phone, $ctx);
        }
        // Ticket-creation step 2: anything except MENU/AGENT is the description.
        if ($session->state === self::STATE_AWAIT_TICKET_DESC) {
            return $this->createTurn($integration, $session, $phone, trim($text), $ctx);
        }
        if ($session->state === self::STATE_HANDED_OFF) {
            // Re-engage on real commands; stay silent on anything else.
            $reply = $this->dispatch($integration, $session, $phone, $first, $args);
            if ($reply !== null && $session->state === self::STATE_HANDED_OFF) {
                $session->update(['state' => self::STATE_MENU]);
            }
            return $reply;
        }
        // Menu state (legacy removed states land here too).
        if ($fresh && !$this->isExecutable($first, $args)) {
            return $this->welcome($integration, $ctx);
        }
        $reply = $this->dispatch($integration, $session, $phone, $first, $args);
        return $reply ?? $this->withFooter($integration, IntegrationSpiels::get($integration, 'fallback'));
    }

    /** First token (uppercased, plural aliases folded) + raw arg tokens. */
    protected function parseCommand(string $text): array
    {
        $tokens = preg_split('/\s+/', trim($text));
        if (!is_array($tokens) || $tokens === []) return ['', []];
        $first = strtoupper($tokens[0]);
        if ($first === 'TICKETS') $first = 'TICKET';
        if ($first === 'BILLING') $first = 'BILL';
        return [$first, array_slice($tokens, 1)];
    }

    /** Fully-formed one-shot (runs immediately, even on first contact). */
    protected function isExecutable(string $first, array $args): bool
    {
        return ($first === 'BILL' || $first === 'TICKET') && count($args) >= 2
            || $first === 'STATUS' && count($args) >= 3;
    }

    /**
     * Run a one-shot command. Null = unrecognized (caller: menu falls back,
     * handed-off stays silent).
     */
    protected function dispatch(Integration $integration, IntegrationSession $session, string $phone, string $first, array $args): ?string
    {
        if ($first === 'BILL' && count($args) >= 2) {
            return $this->billCommand($integration, $session, $args[0], implode(' ', array_slice($args, 1)));
        }
        if ($first === 'STATUS' && count($args) >= 3) {
            return $this->statusCommand($integration, $session,
                $args[0], implode(' ', array_slice($args, 1, -1)), (string) end($args));
        }
        if ($first === 'TICKET' && count($args) >= 2) {
            return $this->ticketStep1($integration, $session, $args[0], implode(' ', array_slice($args, 1)));
        }
        if ($first === 'BILL') return $this->usage($integration, 'bill_usage');
        if ($first === 'STATUS') return $this->usage($integration, 'status_usage');
        if ($first === 'TICKET') return $this->usage($integration, 'ticket_usage');
        return null;
    }

    protected function usage(Integration $integration, string $key): string
    {
        return $this->withFooter($integration, IntegrationSpiels::get($integration, $key));
    }

    /** BILL <account> <code> — balance and billing breakdown. */
    protected function billCommand(Integration $integration, IntegrationSession $session, string $account, string $code): string
    {
        if ($this->lockRemaining($session) > 0) return $this->lockedReply($integration);
        $r = $this->lookupAccount($integration, $account);
        if (!$r['ok']) return $this->lookupError($integration, $r['kind'], 'bill_invalid');
        $err = $this->checkCode($integration, $session, $code, $r['code'], 'bill_invalid');
        if ($err !== null) return $err;
        return $this->sendBillInfo($integration, $session, $r['id'], $r['finance']);
    }

    /** STATUS <account> <code> <ticket> — ticket status details. */
    protected function statusCommand(Integration $integration, IntegrationSession $session, string $account, string $code, string $ticketNo): string
    {
        if (!preg_match('/^\d{4,10}$/', $ticketNo)) {
            return $this->withFooter($integration, IntegrationSpiels::get($integration, 'status_invalid'));
        }
        if ($this->lockRemaining($session) > 0) return $this->lockedReply($integration);
        $r = $this->lookupAccount($integration, $account);
        if (!$r['ok']) return $this->lookupError($integration, $r['kind'], 'status_invalid');
        $err = $this->checkCode($integration, $session, $code, $r['code'], 'status_invalid');
        if ($err !== null) return $err;
        try {
            [$status, $body] = $this->revio->ticket(
                $integration->username, $integration->client_code, $integration->password, $ticketNo);
        } catch (\Throwable $e) {
            Log::warning('Rev.io ticket lookup transport failure: ' . $e->getMessage());
            return $this->withFooter($integration, IntegrationSpiels::get($integration, 'system_error'));
        }
        if ($status === 401 || $status === 403) return $this->authFailed($integration);
        if ($status === 404) {
            return $this->withFooter($integration, IntegrationSpiels::get($integration, 'status_invalid'));
        }
        if ($status < 200 || $status >= 300) {
            Log::warning("Rev.io ticket lookup HTTP {$status} for integration {$integration->id}");
            return $this->withFooter($integration, IntegrationSpiels::get($integration, 'system_error'));
        }
        $ticket = $this->unwrap($body);
        if (!isset($ticket['status']) && !isset($ticket['ticket_id']) && !isset($ticket['id'])) {
            Log::warning('Rev.io ticket lookup unparseable shape', ['keys' => array_keys($ticket)]);
            return $this->withFooter($integration, IntegrationSpiels::get($integration, 'status_invalid'));
        }
        $id = (string) ($ticket['ticket_id'] ?? $ticket['id'] ?? $ticket['number'] ?? $ticketNo);
        $linked = trim((string) ($ticket['customer_id'] ?? ''));
        if ($linked === '' || $linked !== $r['id']) {
            Log::info("Rev.io ticket linkage failed (integration {$integration->id})");
            return $this->withFooter($integration, IntegrationSpiels::get($integration, 'status_invalid'));
        }
        $reply = $this->ticketFoundReply($integration, $ticket, $id);
        $session->update(['state' => self::STATE_MENU]);
        return $reply;
    }

    /** TICKET <account> <code> — step 1: verify, then ask for the issue. */
    protected function ticketStep1(Integration $integration, IntegrationSession $session, string $account, string $code): string
    {
        if ($this->lockRemaining($session) > 0) return $this->lockedReply($integration);
        $r = $this->lookupAccount($integration, $account);
        if (!$r['ok']) return $this->lookupError($integration, $r['kind'], 'ticket_invalid_creds');
        $err = $this->checkCode($integration, $session, $code, $r['code'], 'ticket_invalid_creds');
        if ($err !== null) return $err;
        $this->patchData($session, ['create' => ['customer_id' => $r['id']]]);
        $session->update(['state' => self::STATE_AWAIT_TICKET_DESC]);
        return $this->withShortFooter($integration, IntegrationSpiels::get($integration, 'ticket_ask_desc'));
    }

    /** TICKET flow step 2: the description becomes a Rev.io ticket. */
    protected function createTurn(Integration $integration, IntegrationSession $session, string $phone, string $description, array $ctx): string
    {
        $pending = (is_array($session->data) ? $session->data : [])['create'] ?? null;
        $customerId = trim((string) ($pending['customer_id'] ?? ''));
        if ($customerId === '') {
            $session->update(['state' => self::STATE_MENU]);
            $this->patchData($session, ['create' => null]);
            return $this->usage($integration, 'ticket_usage');
        }
        try {
            $contactName = $this->resolveContactName($ctx, $phone);
        } catch (\Throwable $e) {
            Log::warning('Integration contact lookup failed: ' . $e->getMessage());
            $contactName = '';
        }
        try {
            [$status, $body] = $this->revio->postTicket(
                $integration->username, $integration->client_code, $integration->password, [
                    'customer_id' => (int) $customerId,
                    'contact_phone' => $phone,
                    'description' => $description,
                    'priority_id' => 1,
                    'contact_name' => $contactName,
                    'assigned_group_id' => $integration->ticketGroupId(),
                    'ticket_type_id' => $integration->ticketTypeId(),
                    'ticket_step_id' => $integration->ticketStepId(),
                ]);
        } catch (\Throwable $e) {
            Log::warning('Rev.io ticket create transport failure: ' . $e->getMessage());
            return $this->withFooter($integration, IntegrationSpiels::get($integration, 'system_error'));
        }
        $newId = $this->createdTicketId($body);
        if ($status < 200 || $status >= 300 || $newId === null) {
            Log::warning("Rev.io ticket create failed (integration {$integration->id}): HTTP {$status}");
            return $this->withFooter($integration, IntegrationSpiels::get($integration, 'system_error'));
        }
        $session->update(['state' => self::STATE_MENU]);
        $this->patchData($session, ['create' => null]);
        return IntegrationSpiels::fill(IntegrationSpiels::get($integration, 'ticket_created'),
            ['id' => $newId, 'footer' => IntegrationSpiels::get($integration, 'footer')]);
    }

    protected function createdTicketId(mixed $body): ?string
    {
        if (!is_array($body)) return null;
        $id = $body['id'] ?? $body['ticket_id']
            ?? (is_array($body['data'] ?? null) ? $body['data']['id'] : null)
            ?? (is_array($body['ticket'] ?? null) ? $body['ticket']['id'] : null);
        $id = trim((string) ($id ?? ''));
        return $id !== '' ? $id : null;
    }

    /**
     * Best-effort contact name for the ticket payload (remote Dynalink
     * contacts, matched by phone). Never creates: Dynalink requires a
     * first+last name we don't have.
     */
    protected function resolveContactName(array $ctx, string $phone): string
    {
        $token = (string) ($ctx['token'] ?? '');
        $domain = (string) ($ctx['domain'] ?? '');
        $user = (string) ($ctx['user'] ?? '');
        if ($token === '' || $domain === '' || $user === '') return '';
        $list = $this->dynalink->contacts($token, $domain, $user);
        if (!is_array($list)) return '';
        foreach ($list as $c) {
            if (!is_array($c)) continue;
            foreach (['phonenumber-cell', 'phonenumber-work', 'phonenumber-home'] as $k) {
                if ($this->phoneMatches((string) ($c[$k] ?? ''), $phone)) {
                    return trim(trim((string) ($c['name-first-name'] ?? ''))
                        . ' ' . trim((string) ($c['name-last-name'] ?? '')));
                }
            }
        }
        return '';
    }

    protected function phoneMatches(string $a, string $b): bool
    {
        $da = preg_replace('/\D/', '', $a);
        $db = preg_replace('/\D/', '', $b);
        if ($da === '' || $db === '') return false;
        if ($da === $db) return true;
        $na = strlen($da) === 11 && $da[0] === '1' ? substr($da, 1) : $da;
        $nb = strlen($db) === 11 && $db[0] === '1' ? substr($db, 1) : $db;
        return $na === $nb;
    }

    protected function ticketFoundReply(Integration $integration, array $ticket, string $id): string
    {
        $statusRaw = strtoupper(trim((string) ($ticket['status'] ?? '')));
        $displayStatus = trim((string) ($ticket['custom_status'] ?? '')) !== ''
            ? trim((string) $ticket['custom_status'])
            : (($ticket['status'] ?? '') !== '' ? (string) $ticket['status'] : $statusRaw);
        $desc = $this->plain((string) ($ticket['description'] ?? ''), 400);
        if (in_array($statusRaw, ['OPEN', 'INWORK'], true)) {
            $lines = ["Ticket #: {$id}", "Status: {$displayStatus}"];
            if ($desc !== '') $lines[] = "Description: {$desc}";
            $update = $this->fieldValue($ticket, 148, 400);
            if ($update !== '') $lines[] = "Latest Update: {$update}";
            if (count($lines) === 2) $lines[] = 'No details available.';
            $tpl = 'ticket_found_open';
        } else {
            $lines = ["Ticket #: {$id}", "Status: {$displayStatus}"];
            if ($desc !== '') $lines[] = "Description: {$desc}";
            $res = $this->plain((string) ($ticket['resolution'] ?? ''), 400);
            if ($res !== '') $lines[] = "Resolution: {$res}";
            $closed = $this->dateOnly($ticket['closed_date'] ?? null);
            if ($closed !== null) $lines[] = "Closed Date: {$closed}";
            if (count($lines) === 2) $lines[] = 'No details available.';
            $tpl = 'ticket_found_closed';
        }
        $this->postJournal($integration, $id);
        return IntegrationSpiels::fill(IntegrationSpiels::get($integration, $tpl),
            ['id' => $id, 'status' => $displayStatus, 'details' => implode("\n", $lines),
             'footer' => IntegrationSpiels::get($integration, 'footer')]);
    }

    /** Welcome with the company name and menu resolved. */
    protected function welcome(Integration $integration, array $ctx): string
    {
        return IntegrationSpiels::fill(IntegrationSpiels::get($integration, 'welcome'),
            ['CompanyName' => $this->companyName((string) ($ctx['domain'] ?? '')),
             'Menu' => IntegrationSpiels::get($integration, 'menu')]);
    }

    /** Append the standard footer to a dialog message. */
    protected function withFooter(Integration $integration, string $text): string
    {
        $footer = IntegrationSpiels::get($integration, 'footer');
        return $footer !== '' ? $text . "\n\n" . $footer : $text;
    }

    /** Append the short mid-flow footer ("MENU to go back"). */
    protected function withShortFooter(Integration $integration, string $text): string
    {
        $footer = IntegrationSpiels::get($integration, 'footer_short');
        return $footer !== '' ? $text . "\n\n" . $footer : $text;
    }

    protected function companyName(string $domain): string
    {
        try {
            $name = trim((string) app(\App\Services\CompanySettingsService::class)->name($domain));
            if ($name !== '') return $name;
            $tenant = $domain !== '' ? trim((string) Tenant::where('domain', $domain)->value('company_name')) : '';
            if ($tenant !== '') return $tenant;
        } catch (\Throwable $e) {
            // Fall through to the grammatical fallback.
        }
        return 'us';
    }

    /** Merge keys into session data (null value removes the key). */
    protected function patchData(IntegrationSession $session, array $patch): array
    {
        $d = is_array($session->data) ? $session->data : [];
        foreach ($patch as $k => $v) {
            if ($v === null) unset($d[$k]); else $d[$k] = $v;
        }
        $session->update(['data' => $d]);
        return $d;
    }

    /** Minutes of code-lockout remaining (0 = not locked). Expired locks clear. */
    protected function lockRemaining(IntegrationSession $session): int
    {
        $until = (int) ((is_array($session->data) ? $session->data : [])['code_locked_until'] ?? 0);
        if ($until <= 0) return 0;
        if ($until <= time()) {
            $this->patchData($session, ['code_locked_until' => null, 'code_attempts' => null]);
            return 0;
        }
        return (int) ceil(($until - time()) / 60);
    }

    protected function recordCodeFail(Integration $integration, IntegrationSession $session): void
    {
        $d = is_array($session->data) ? $session->data : [];
        $n = (int) ($d['code_attempts'] ?? 0) + 1;
        if ($n >= $integration->codeMaxAttempts()) {
            $this->patchData($session, ['code_attempts' => null,
                'code_locked_until' => time() + $integration->codeLockoutMinutes() * 60]);
            Log::info("Integration code lockout (integration {$integration->id})");
        } else {
            $this->patchData($session, ['code_attempts' => $n]);
        }
    }

    protected function resetCodeFails(IntegrationSession $session): void
    {
        $this->patchData($session, ['code_attempts' => null, 'code_locked_until' => null]);
    }

    protected function lockedReply(Integration $integration): string
    {
        return $this->withFooter($integration, IntegrationSpiels::fill(
            IntegrationSpiels::get($integration, 'bill_locked'),
            ['minutes' => $integration->codeLockoutMinutes()]));
    }

    /**
     * Fetch a Rev.io customer for the one-shot commands.
     * ['ok'=>true,'id','code','finance'] or ['ok'=>false,'kind'] where kind
     * is not_found | no_code | system | auth.
     */
    protected function lookupAccount(Integration $integration, string $text): array
    {
        try {
            [$status, $body] = $this->revio->customer(
                $integration->username, $integration->client_code, $integration->password, $text);
        } catch (\Throwable $e) {
            Log::warning('Rev.io customer lookup transport failure: ' . $e->getMessage());
            return ['ok' => false, 'kind' => 'system'];
        }
        if ($status === 401 || $status === 403) return ['ok' => false, 'kind' => 'auth'];
        if ($status === 404) return ['ok' => false, 'kind' => 'not_found'];
        if ($status < 200 || $status >= 300) {
            Log::warning("Rev.io customer lookup HTTP {$status} for integration {$integration->id}");
            return ['ok' => false, 'kind' => 'system'];
        }
        $customer = $this->unwrap($body);
        if (!isset($customer['finance']) && !isset($customer['customer_id']) && !isset($customer['id'])) {
            Log::warning('Rev.io customer lookup unparseable shape', ['keys' => array_keys($customer)]);
            return ['ok' => false, 'kind' => 'not_found'];
        }
        $id = (string) ($customer['customer_id'] ?? $customer['id'] ?? $customer['number'] ?? $text);
        $code = trim((string) ($customer['registration_code'] ?? ''));
        if ($code === '') {
            Log::info("Rev.io account has no registration code on file (integration {$integration->id})");
            return ['ok' => false, 'kind' => 'no_code'];
        }
        return ['ok' => true, 'id' => $id, 'code' => $code,
            'finance' => is_array($customer['finance'] ?? null) ? $customer['finance'] : []];
    }

    /** Map a lookup failure to the caller's generic spiel (no oracles). */
    protected function lookupError(Integration $integration, string $kind, string $invalidKey): string
    {
        if ($kind === 'auth') return $this->authFailed($integration);
        if ($kind === 'system') {
            return $this->withFooter($integration, IntegrationSpiels::get($integration, 'system_error'));
        }
        return $this->withFooter($integration, IntegrationSpiels::get($integration, $invalidKey));
    }

    /**
     * Compare a caller-typed code against the expected one.
     * Null = match (fails reset). String = reply to send (invalid or locked).
     */
    protected function checkCode(Integration $integration, IntegrationSession $session, string $input, string $expected, string $invalidKey): ?string
    {
        if ($this->lockRemaining($session) > 0) return $this->lockedReply($integration);
        if ($this->normCode($input) !== $this->normCode($expected)) {
            $this->recordCodeFail($integration, $session);
            return $this->lockRemaining($session) > 0
                ? $this->lockedReply($integration)
                : $this->withFooter($integration, IntegrationSpiels::get($integration, $invalidKey));
        }
        $this->resetCodeFails($session);
        return null;
    }

    /** Whitespace-tolerant code normalization for comparison. */
    protected function normCode(string $v): string
    {
        return mb_strtolower((string) preg_replace('/\s+/', ' ', trim($v)));
    }

    protected function sendBillInfo(Integration $integration, IntegrationSession $session, string $id, array $fin): string
    {
        $session->update(['state' => self::STATE_MENU]);
        return IntegrationSpiels::fill(IntegrationSpiels::get($integration, 'bill_found'),
            ['id' => $id, 'details' => $this->billDetails($fin, $id),
             'footer' => IntegrationSpiels::get($integration, 'footer')]);
    }

    protected function billDetails(array $fin, string $id): string
    {
        $lines = ["Account #: {$id}"];
        $due = $this->money($fin['amount_due'] ?? null);
        $ovd = $this->money($fin['amount_overdue'] ?? null);
        $bal = $this->money($fin['balance'] ?? null);
        if ($bal !== null) $lines[] = "Balance: {$bal}";
        if ($due !== null) $lines[] = "Amount Due: {$due}";
        if ($ovd !== null) $lines[] = "Amount Overdue: {$ovd}";
        $dueDate = $this->dateOnly($fin['due_date'] ?? null);
        if ($dueDate !== null) $lines[] = "Due Date: {$dueDate}";
        $cycle = $this->dateOnly($fin['cycle_date'] ?? null);
        if ($cycle !== null) $lines[] = "Cycle Date: {$cycle}";
        if (count($lines) === 1) $lines[] = "Billing details aren't available for this account.";
        return implode("\n", $lines);
    }

    /**
     * AGENT keyword: open a fresh Dynalink session (random 32-char id),
     * send the handoff ack there, and park it in the Queue folder.
     * Returns null — the ack already went out on the new session, so
     * nothing is sent back on the dialog session. The dialog goes quiet
     * (handed_off) until the session expires.
     */
    protected function agentTurn(Integration $integration, IntegrationSession $session, string $phone, array $ctx): ?string
    {
        $this->patchData($session, ['create' => null]);
        $token = (string) ($ctx['token'] ?? '');
        $sender = (string) ($ctx['sender'] ?? '');
        $domain = (string) ($ctx['domain'] ?? '');
        $user = (string) ($ctx['user'] ?? '');
        if ($token === '' || $sender === '' || $domain === '' || $user === '') {
            Log::warning("Integration agent handoff missing context for integration {$integration->id}");
            return $this->withFooter($integration, IntegrationSpiels::get($integration, 'system_error'));
        }
        $open = $this->withinHours($integration);
        $newId = DynalinkService::randomSessionId();
        try {
            [$status] = $this->dynalink->sendInSession($token, $domain, $user, $newId, [
                'type' => 'sms',
                'message' => $open
                    ? IntegrationSpiels::get($integration, 'agent_ack')
                    : IntegrationSpiels::get($integration, 'agent_closed'),
                'destination' => $phone,
                'from-number' => $sender,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Integration agent handoff send failed: ' . $e->getMessage());
            return $this->withFooter($integration, IntegrationSpiels::get($integration, 'system_error'));
        }
        if ($status < 200 || $status >= 300) {
            Log::warning("Integration agent handoff send HTTP {$status} for integration {$integration->id}");
            return $this->withFooter($integration, IntegrationSpiels::get($integration, 'system_error'));
        }
        try {
            ConversationMeta::updateOrCreate(
                ['domain' => $domain, 'user' => $user, 'session_id' => $newId],
                ['status' => 'queued', 'agent_id' => null]
            );
        } catch (\Throwable $e) {
            Log::warning('Integration queue assignment failed: ' . $e->getMessage());
        }
        $session->update(['state' => self::STATE_HANDED_OFF]);
        DataChanged::send($domain, $user, 'sessions', 'message-sent', null, ['remote' => $phone]);
        DataChanged::send($domain, $user, 'convo-meta', 'saved', $newId, ['status' => 'queued']);
        return null;
    }

    /** Server-local business-hours check (supports overnight ranges). */
    protected function withinHours(Integration $integration): bool
    {
        $hours = $integration->businessHours();
        $row = $hours[strtolower(now()->format('D'))] ?? ['open' => false];
        if (empty($row['open'])) return false;
        $t = now()->format('H:i');
        [$start, $end] = [$row['start'], $row['end']];
        return $end <= $start
            ? ($t >= $start || $t < $end)   // overnight, e.g. 22:00-06:00
            : ($t >= $start && $t < $end);
    }

    /** Stored credentials rejected mid-dialog: flag the row so the admin notices. */
    protected function authFailed(Integration $integration): string
    {
        Log::warning("Rev.io lookup auth failure for integration {$integration->id}");
        $integration->update(['status' => 'error', 'last_checked_at' => now(),
            'last_error' => 'Rev.io rejected the credentials during a lookup.']);
        return $this->withFooter($integration, IntegrationSpiels::get($integration, 'system_error'));
    }

    /** Bookkeeping: note the SMS status check on the Rev.io ticket. Never fails the reply. */
    protected function postJournal(Integration $integration, string $ticketId): void
    {
        try {
            $payload = [
                'message' => $integration->revioNote(),
                'ticket_id' => (int) $ticketId,
                'visible_in_agent_portal' => true,
                'email_copy_to_group' => true,
            ];
            $by = $integration->revioUserId();
            if ($by !== null) $payload['created_by'] = $by;
            [$status] = $this->revio->ticketJournal(
                $integration->username, $integration->client_code, $integration->password, $payload);
            if ($status < 200 || $status >= 300) {
                Log::warning("Rev.io TicketJournal HTTP {$status} for integration {$integration->id}");
            }
        } catch (\Throwable $e) {
            Log::warning('Rev.io TicketJournal failed: ' . $e->getMessage());
        }
    }

    /** Accept a direct object or a {data,ticket,customer,result} wrapper. */
    protected function unwrap(mixed $body): array
    {
        if (!is_array($body)) return [];
        foreach (['data', 'ticket', 'customer', 'result'] as $k) {
            if (isset($body[$k]) && is_array($body[$k])) return $body[$k];
        }
        return $body;
    }

    protected function fieldValue(array $obj, int $fieldId, int $max): string
    {
        foreach (($obj['fields'] ?? []) as $f) {
            if (is_array($f) && (int) ($f['field_id'] ?? -1) === $fieldId) {
                return $this->plain((string) ($f['value'] ?? ''), $max);
            }
        }
        return '';
    }

    /** HTML -> plain SMS-safe text, whitespace-collapsed, truncated. */
    protected function plain(string $html, int $max): string
    {
        $s = trim((string) preg_replace('/\s+/', ' ',
            html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)));
        if (mb_strlen($s) > $max) $s = rtrim(mb_substr($s, 0, $max - 3)) . '...';
        return $s;
    }

    protected function money(mixed $v): ?string
    {
        return is_numeric($v) ? '$' . number_format((float) $v, 2) : null;
    }

    /** Date-only, medium alphanumeric ("Sep 02, 2021"); null for sentinels. */
    protected function dateOnly(mixed $v): ?string
    {
        $s = trim((string) $v);
        if ($s === '' || str_starts_with($s, '0001')) return null;
        try {
            $d = new \DateTime($s);
        } catch (\Throwable $e) {
            return null;
        }
        if ((int) $d->format('Y') < 1900) return null;
        return $d->format('M d, Y');
    }

    /** Drop sessions idle more than a day past expiry (table hygiene). */
    protected function prune(Integration $integration): void
    {
        try {
            IntegrationSession::where('integration_id', $integration->id)
                ->where('expires_at', '<', now()->subDay())->delete();
        } catch (\Throwable $e) {
            // Hygiene only — never break the dialog.
        }
    }
}
