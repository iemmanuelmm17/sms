<?php

namespace App\Jobs;

use App\Models\ScheduledMessage;
use App\Services\DynalinkService;
use App\Services\OptOutService;
use App\Events\DataChanged;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\SentMessageLog;

/**
 * Sends ONE scheduled message to ONE recipient.
 * Dispatched once per recipient so sends are strictly 1-by-1.
 */
class SendScheduledMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $scheduledId,
        public int $recipientIndex,
    ) {}

    public function handle(DynalinkService $dynalink, OptOutService $optouts): void
    {
        $m = ScheduledMessage::find($this->scheduledId);
        // Cancelled, already sent, or superseded (send-now fired immediate
        // jobs) → skip. Retry sets status back to 'sending' first, so it
        // still proceeds.
        if (!$m || in_array($m->status, ['cancelled', 'sent', 'partial'], true)) return;

        $recipient = $m->recipients[$this->recipientIndex] ?? null;
        if (!$recipient) return;

        // Idempotency: this recipient already got a confirmed send → never resend.
        $sentPhones = collect($m->send_log ?? [])->where('ok', true)
            ->map(fn($l) => preg_replace('/\\D/', '', (string) ($l['phone'] ?? '')));
        if ($sentPhones->contains(preg_replace('/\\D/', '', (string) ($recipient['phone'] ?? '')))) return;

        // Overlap guard: only one worker attempt per recipient at a time.
        $idemKey = "sched:send:{$m->id}:{$this->recipientIndex}";
        if (!\Illuminate\Support\Facades\Cache::add($idemKey, 1, now()->addMinutes(15))) return;

        // If rescheduled to the future, re-dispatch remaining for new time.
        if (now()->lt($m->send_at)) {
            self::dispatch($m->id, $this->recipientIndex)
                ->delay($m->send_at->copy()->addSeconds($this->recipientIndex * 2));
            \Illuminate\Support\Facades\Cache::forget($idemKey);
            return;
        }

        // TCPA: skip opted-out recipients (logged, never sent).
        if ($optouts->isOptedOut($m->domain, (string) ($recipient['phone'] ?? ''), (string) $m->from_number)) {
            $this->logResult($m, $recipient, false, 'skipped: number opted out (do-not-contact)');
            \Illuminate\Support\Facades\Cache::forget($idemKey);
            return;
        }

        // We need a valid user token. Tokens live in session, so for queued
        // sends the scheduler re-authenticates with a service credential.
        // Configure DYNALINK_SERVICE_USER / DYNALINK_SERVICE_PASS in .env,
        // OR store per-user refresh tokens server-side (see README).
        // Outer guard: EVERY exit below logs a result — a send can never
        // stick at 'sending' again (service-login failure, bad payload, ...).
        try {
                $token = $this->serviceToken($dynalink, $m);

        // Per-recipient personalization: {col1}/{col2}/{col3} from CSV, {name}, {phone}.
        $vars = array_merge(
            ['col1' => '', 'col2' => '', 'col3' => ''],
            $recipient['vars'] ?? []
        );
        $text = str_replace(
            ['{col1}', '{col2}', '{col3}', '{name}', '{phone}'],
            [$vars['col1'], $vars['col2'], $vars['col3'], $recipient['name'] ?? '', $recipient['phone'] ?? ''],
            $m->message
        );
        // $FirstName / $LastName per-recipient (snapshot carries both; '' for legacy rows).
        $text = str_replace(
            ['$FirstName', '$LastName'],
            [$recipient['first_name'] ?? '', $recipient['last_name'] ?? ''],
            $text
        );

        // Resolve $CompanyName, then the TCPA bulk wrap (5+ recipients):
        // "Company: body\n<footer>" — footer is Action A's live text.
        $companySvc = app(\App\Services\CompanySettingsService::class);
        $text = $companySvc->resolve($m->domain, $text, (string) ($m->created_by_name ?? ''));
        // Bulk TCPA wrap — off when the composer's "Add TCPA Script Footer" is unchecked.
        if (count($m->recipients ?? []) >= 5 && $m->tcpa_script !== false) {
            $company = $companySvc->name($m->domain);
            // One resolver: TCPA page setting → opt-out default → literal.
            // The old (domain,user) lookup missed for portal agents, whose
            // `user` is an extension rather than the tenant's dynalink_user.
            $footer = $companySvc->tcpaFooter($m->domain, $m->user);
            $text = ($company !== '' ? $company . ': ' : '') . $text . "\n" . $footer;
        }

        $payload = [
            'type'        => $m->type,
            'message'     => $text,
            'destination' => $recipient['phone'],
            'from-number' => $m->from_number,
        ];
        if ($m->type === 'mms') {
            $payload['data'] = $m->media_data;
            $payload['mime-type'] = $m->media_mime;
            $payload['size'] = $m->media_size;
        }

        try {
            [$status, $body] = $dynalink->sendNew($token, $m->domain, $m->user, $payload);
            $ok = $status >= 200 && $status < 300;
            $this->logResult($m, $recipient, $ok, $ok ? 'sent' : json_encode($body));
            if ($ok) {
                $trigId = null;
                if (preg_match('/^agent:(\d+)$/', (string) $m->created_by, $mm)) $trigId = (int) $mm[1];
                $toDigits = preg_replace('/\D/', '', (string) ($recipient['phone'] ?? ''));
                SentMessageLog::record([
                    'tenant_id' => SentMessageLog::tenantIdFor($m->domain, $m->user),
                    'domain' => $m->domain, 'user' => $m->user,
                    'agent_id' => $trigId,
                    'actor_name' => $m->created_by_name,
                    'category' => SentMessageLog::MASS_SMS,
                    'scheduled_message_id' => $m->id,
                    'session_id' => is_array($body) ? ($body['messagesession-id'] ?? $body['messagesession_id'] ?? null) : null,
                    'from_number' => preg_replace('/\D/', '', (string) $m->from_number),
                    'to_number' => $toDigits !== '' ? $toDigits : null,
                    'type' => $m->type ?? 'sms',
                ]);
            }
            if (!$ok) \Illuminate\Support\Facades\Cache::forget($idemKey);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Cache::forget($idemKey);
            $this->logResult($m, $recipient, false, $e->getMessage());
        }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Cache::forget($idemKey);
            $this->logResult($m, $recipient, false, 'send error: '.$e->getMessage());
        }
    }

    /**
     * Clone a finished recurring occurrence at its next slot and dispatch the
     * per-recipient jobs. Called once, when the last recipient reports.
     *
     * Recipients come from the snapshot taken when the series was created, so
     * a series sends to the same audience every time — predictable, and it
     * can't surprise someone by growing under them.
     */
    protected function spawnNextOccurrence(ScheduledMessage $m): void
    {
        $freq = (string) ($m->recurrence ?? '');
        if ($freq === '') return;

        // Guard: logResult runs per recipient, so several workers can land on
        // the "all done" state at once. Only the first one may spawn.
        $guard = "sched:recur:spawn:{$m->id}";
        if (!\Illuminate\Support\Facades\Cache::add($guard, 1, now()->addHours(12))) return;

        $interval = max(1, (int) ($m->recur_interval ?: 1));
        $base = $m->send_at ? \Carbon\Carbon::parse($m->send_at) : now();
        $next = match ($freq) {
            'daily'   => $base->copy()->addDays($interval),
            'weekly'  => $base->copy()->addWeeks($interval),
            'monthly' => $base->copy()->addMonthsNoOverflow($interval),
            default   => null,
        };
        if (!$next) return;

        $idx = ((int) ($m->recur_index ?? 0)) + 1;                        // occurrences already sent
        if ($m->recur_until && $next->gt($m->recur_until)) return;        // ended by date
        if ($m->recur_occurrences && $idx > (int) $m->recur_occurrences) return; // ended by count

        $copy = $m->replicate();
        $copy->parent_id = $m->parent_id ?: $m->id;
        $copy->recur_index = $idx;
        $copy->send_at = $next;
        $copy->status = 'pending';
        $copy->send_log = [];
        $copy->created_at = now();
        $copy->updated_at = now();
        $copy->save();

        foreach (array_values((array) ($m->recipients ?? [])) as $i => $r) {
            self::dispatch($copy->id, $i)->delay($next->copy()->addSeconds($i * 2));
        }
        DataChanged::send($m->domain, $m->user, 'scheduled', 'saved', $copy->id);
    }

    protected function logResult(ScheduledMessage $m, array $recipient, bool $ok, string $detail): void
    {
        $log = $m->send_log ?? [];
        $log[] = [
            'phone' => $recipient['phone'], 'name' => $recipient['name'] ?? '',
            'ok' => $ok, 'detail' => $detail, 'at' => now()->toISOString(),
        ];
        $m->send_log = $log;
        // Latest result per phone wins (retries can log the same phone twice).
        $latest = [];
        foreach ($log as $l) {
            $latest[preg_replace('/\D/', '', (string) ($l['phone'] ?? ''))] = !empty($l['ok']);
        }
        unset($latest['']);
        $need = collect($m->recipients ?? [])->map(fn($r) => preg_replace('/\D/', '', (string) ($r['phone'] ?? '')))->filter()->unique()->values();
        if ($need->every(fn($d) => array_key_exists($d, $latest))) {
            $m->status = $need->every(fn($d) => $latest[$d]) ? 'sent' : 'partial';
        } else {
            $m->status = 'sending';
        }
        $m->save();
        // Recurring series: once every recipient of this occurrence has a
        // result, queue up the next one (no cron — the queue drives it).
        if (in_array($m->status, ['sent', 'partial'], true)) {
            try { $this->spawnNextOccurrence($m); } catch (\Throwable $e) { /* never break reporting */ }
        }
        DataChanged::send($m->domain, $m->user, 'scheduled', 'saved', $m->id);
    }

    protected function serviceToken(DynalinkService $dynalink, ScheduledMessage $m): string
    {
        // Option A: per-user stored refresh token (recommended for production).
        // Option B (simple): service user login. Implement per deployment.
        $cacheKey = "dynalink:service_token:{$m->domain}:{$m->user}";
        return \Illuminate\Support\Facades\Cache::remember($cacheKey, 3000, function () use ($dynalink) {
            $tokens = $dynalink->login(
                \App\Services\Settings::dynalinkServiceCredential('user'),
                \App\Services\Settings::dynalinkServiceCredential('pass')
            );
            return $tokens['access_token'];
        });
    }
}

