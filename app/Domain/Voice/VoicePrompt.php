<?php

namespace App\Domain\Voice;

use App\Domain\Voice\Models\VoiceAgent;

/** Builds what the voice model is told: the agent's instructions plus FlowForge's booking rules and tool. */
class VoicePrompt
{
    /** Replaces {{business_name}} (set once on the agent); per-call details like {{customer_name}} stay for the provider. */
    public static function fill(VoiceAgent $agent, string $text, array $variables = []): string
    {
        $values = ['business_name' => (string) $agent->setting('business_name', '')] + $variables;

        return preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', fn ($m) => isset($values[$m[1]]) && $values[$m[1]] !== '' ? (string) $values[$m[1]] : $m[0], $text);
    }

    /** The {{variables}} an agent's texts use besides business_name: what to fill in for a call. */
    public static function variables(VoiceAgent $agent): array
    {
        preg_match_all('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', $agent->setting('first_message', '').' '.$agent->setting('instructions', ''), $m);

        return array_values(array_diff(array_unique($m[1]), ['business_name']));
    }

    public static function system(VoiceAgent $agent, array $variables = []): string
    {
        $parts = [trim(self::fill($agent, (string) $agent->setting('instructions', ''), $variables))];
        $parts[] = 'You are speaking on a phone call. Keep replies short and natural (one or two sentences), never use lists, markdown or emojis, and say numbers, dates and times the way people speak them.';
        if ($agent->bookingEnabled()) {
            $hours = trim((string) $agent->setting('booking.hours', ''));
            $parts[] = 'Booking: when the caller wants an appointment, collect their full name, phone number'
                .($agent->setting('booking.ask_email') ? ', email address' : '')
                .' and the date and time they want'.($hours !== '' ? " (we are open {$hours})" : '')
                .'. Read the details back, and once the caller agrees, call the book_appointment tool. Tell the caller the time the tool confirms. If the tool reports a problem, ask for another time.';
        }

        return implode("\n\n", array_filter($parts));
    }

    /** The booking tool, in the common JSON-schema shape every provider accepts. */
    public static function bookingTool(VoiceAgent $agent): array
    {
        $properties = [
            'name' => ['type' => 'string', 'description' => "The caller's full name"],
            'phone' => ['type' => 'string', 'description' => "The caller's phone number"],
            'email' => ['type' => 'string', 'description' => "The caller's email address, if they gave one"],
            'service' => ['type' => 'string', 'description' => 'What the appointment is for'],
            'date_time' => ['type' => 'string', 'description' => 'The agreed date and time, e.g. "2026-10-20 15:00" or "next Tuesday at 3pm"'],
            'notes' => ['type' => 'string', 'description' => 'Anything else the caller asked to note'],
        ];

        return [
            'name' => 'book_appointment',
            'description' => 'Book the appointment once the caller has confirmed their name and the date and time. Returns the confirmed time, or a problem to resolve with the caller.',
            'parameters' => ['type' => 'object', 'properties' => $properties, 'required' => $agent->setting('booking.ask_email') ? ['name', 'date_time', 'email'] : ['name', 'date_time']],
        ];
    }

    /** JSON schema of the details to pull out of each call (the agent's "collect" list). */
    public static function collectSchema(VoiceAgent $agent): ?array
    {
        $fields = collect($agent->setting('collect', []))->filter(fn ($f) => ! empty($f['key']));

        return $fields->isEmpty() ? null : ['type' => 'object', 'properties' => $fields->mapWithKeys(fn ($f) => [$f['key'] => ['type' => 'string', 'description' => (string) ($f['description'] ?? $f['key'])]])->all()];
    }

    public static function outcome(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $v = is_bool($value) ? ($value ? 'true' : 'false') : strtolower(trim((string) $value));

        return in_array($v, ['true', 'pass', 'success', 'yes', '1'], true) ? 'success' : (in_array($v, ['false', 'fail', 'failure', 'no', '0'], true) ? 'failure' : 'unknown');
    }
}
