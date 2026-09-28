<?php

namespace App\Services;

use App\Models\Integration;

/**
 * Admin-editable dialog messages ("spiels") per provider.
 * Defaults live here (ASCII-safe for SMS); admin overrides are stored
 * on the integration row and merged over them. A blank override —
 * or one identical to the default — resets that message to default.
 *
 * One-shot command replies carry the standard footer; the ticket
 * description prompt carries the short footer. Welcome has its own
 * menu closer and the agent handoff its own text (no footer on either).
 */
class IntegrationSpiels
{
    public const REVIO = [
        'welcome' => [
            'label' => 'Welcome / initial message',
            'placeholders' => ['{CompanyName}', '{Menu}'],
            'default' => "Thank you for contacting {CompanyName}!\n\n{Menu}",
        ],
        'menu' => [
            'label' => 'Main menu options',
            'placeholders' => [],
            'default' => "Reply with one of the following options:\nBILL <Account#> <Code>\nSTATUS <Account#> <Code> <Ticket#>\nTICKET <Account#> <Code>\nAGENT - Speak with a live representative",
        ],
        'footer' => [
            'label' => 'Standard footer',
            'placeholders' => [],
            'default' => 'Reply MENU, TICKET, BILL, or AGENT.',
        ],
        'footer_short' => [
            'label' => 'Short footer (mid-flow)',
            'placeholders' => [],
            'default' => 'Reply MENU to go back.',
        ],
        'fallback' => [
            'label' => 'Fallback: invalid command',
            'placeholders' => [],
            'default' => 'Invalid command. Please reply with BILL, STATUS, TICKET, or AGENT.',
        ],
        'bill_usage' => [
            'label' => 'Usage: BILL',
            'placeholders' => [],
            'default' => 'Usage: BILL <Account#> <Code>.',
        ],
        'status_usage' => [
            'label' => 'Usage: STATUS',
            'placeholders' => [],
            'default' => 'Usage: STATUS <Account#> <Code> <Ticket#>.',
        ],
        'ticket_usage' => [
            'label' => 'Usage: TICKET',
            'placeholders' => [],
            'default' => 'Usage: TICKET <Account#> <Code>.',
        ],
        'bill_invalid' => [
            'label' => 'BILL: invalid account or code',
            'placeholders' => [],
            'default' => 'Billing account not found, please enter a different account number and code.',
        ],
        'status_invalid' => [
            'label' => 'STATUS: invalid account, code, or ticket',
            'placeholders' => [],
            'default' => 'Invalid account, code, or ticket number.',
        ],
        'ticket_invalid_creds' => [
            'label' => 'TICKET: invalid account or code',
            'placeholders' => [],
            'default' => 'Invalid account number or registration code. Please check your details and try again.',
        ],
        'ticket_ask_desc' => [
            'label' => 'TICKET: ask for description',
            'placeholders' => [],
            'default' => 'Please briefly describe your issue to complete your ticket creation.',
        ],
        'ticket_created' => [
            'label' => 'TICKET: created',
            'placeholders' => ['{id}', '{footer}'],
            'default' => "Ticket #{id} created! An agent will review it shortly.\n\n{footer}",
        ],
        'ticket_found_open' => [
            'label' => 'Ticket: found (open / in work)',
            'placeholders' => ['{details}', '{footer}'],
            'default' => "{details}\n\n{footer}",
        ],
        'ticket_found_closed' => [
            'label' => 'Ticket: found (closed / other)',
            'placeholders' => ['{details}', '{footer}'],
            'default' => "{details}\n\n{footer}",
        ],
        'bill_found' => [
            'label' => 'Billing: account found',
            'placeholders' => ['{details}', '{footer}'],
            'default' => "{details}\n\n{footer}",
        ],
        'bill_locked' => [
            'label' => 'Billing: too many attempts',
            'placeholders' => ['{minutes}'],
            'default' => 'Too many incorrect codes. Please try again in {minutes} minutes.',
        ],
        'system_error' => [
            'label' => 'System unreachable',
            'placeholders' => [],
            'default' => 'Sorry, our system isn\'t responding right now. Please try again in a few minutes.',
        ],
        'agent_ack' => [
            'label' => 'Agent handoff: confirmation',
            'placeholders' => [],
            'default' => 'A representative will be with you shortly. In the meantime, please tell us how we can help you. Reply MENU to return to automated options.',
        ],
        'agent_closed' => [
            'label' => 'Agent handoff: outside hours',
            'placeholders' => [],
            'default' => 'We\'re currently closed. An agent will reply during business hours. Reply MENU to return to automated options.',
        ],
    ];

    public static function defs(string $provider): array
    {
        return $provider === Integration::PROVIDER_REVIO ? self::REVIO : [];
    }

    public static function keys(string $provider): array
    {
        return array_keys(self::defs($provider));
    }

    /** key => default text (for Reset-to-default in the editor). */
    public static function defaults(string $provider): array
    {
        $out = [];
        foreach (self::defs($provider) as $key => $def) {
            $out[$key] = $def['default'];
        }
        return $out;
    }

    /** key => [label, placeholders] for the editor UI. */
    public static function meta(string $provider): array
    {
        $out = [];
        foreach (self::defs($provider) as $key => $def) {
            $out[$key] = ['label' => $def['label'], 'placeholders' => $def['placeholders']];
        }
        return $out;
    }

    /** Custom override keys currently stored on the row. */
    public static function customizedKeys(?Integration $row): array
    {
        if (!$row || !is_array($row->spiels)) return [];
        return array_values(array_filter(array_keys($row->spiels),
            fn($k) => trim((string) $row->spiels[$k]) !== ''));
    }

    /** Overrides merged over defaults (blank override = default). */
    public static function merged(?Integration $row, string $provider): array
    {
        $out = [];
        foreach (self::defs($provider) as $key => $def) {
            $out[$key] = self::get($row, $key, $provider);
        }
        return $out;
    }

    public static function get(?Integration $row, string $key, ?string $provider = null): string
    {
        $custom = ($row && is_array($row->spiels) && isset($row->spiels[$key]))
            ? trim((string) $row->spiels[$key]) : '';
        if ($custom !== '') return $custom;
        $provider ??= $row?->provider ?? '';
        return (string) (self::defs($provider)[$key]['default'] ?? '');
    }

    /** Replace {placeholder} tokens. Unknown tokens are left as-is. */
    public static function fill(string $template, array $vars): string
    {
        foreach ($vars as $k => $v) {
            $template = str_replace('{' . $k . '}', (string) $v, $template);
        }
        return $template;
    }
}
