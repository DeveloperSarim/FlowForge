<?php

namespace App\Domain\Voice;

use App\Domain\Voice\Models\VoiceAgent;

/** Builds what the voice model is told: the agent's instructions plus FlowForge's booking rules and tool. */
class VoicePrompt
{
    /** Replaces {{business_name}} (set once on the agent); per-call details like {{customer_name}} stay for the provider. */
    public static function fill(VoiceAgent $agent, string $text, array $variables = []): string
    {
        $values = ['business_name' => (string) $agent->setting('business_name', '') ?: 'our business'] + $variables; // never speak a raw {{business_name}}

        return preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', fn ($m) => isset($values[$m[1]]) && $values[$m[1]] !== '' ? (string) $values[$m[1]] : $m[0], $text);
    }

    /** The {{variables}} an agent's texts use besides business_name: what to fill in for a call. */
    public static function variables(VoiceAgent $agent): array
    {
        preg_match_all('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', $agent->setting('first_message', '').' '.$agent->setting('instructions', ''), $m);

        return array_values(array_diff(array_unique($m[1]), ['business_name']));
    }

    public const TONES = [
        'warm' => 'Sound warm and friendly, like someone who enjoys helping.',
        'professional' => 'Sound calm, polite and professional.',
        'upbeat' => 'Sound upbeat and energetic, with a smile in your voice.',
        'calm' => 'Sound calm and reassuring, and speak a little slower.',
    ];

    private const LENGTHS = [
        'short' => 'Keep every reply to one short sentence when you can.',
        'balanced' => 'Keep replies short and natural: one or two sentences.',
        'detailed' => 'Give complete answers in two to four sentences when the caller asks for details.',
    ];

    public static function system(VoiceAgent $agent, array $variables = []): string
    {
        $persona = trim((string) $agent->setting('persona_name', ''));
        $parts = [trim(($persona !== '' ? "Your name is {$persona}. " : '').self::fill($agent, (string) $agent->setting('instructions', ''), $variables))];
        $parts[] = 'You are speaking on a phone call. '.(self::TONES[$agent->setting('tone', 'warm')] ?? self::TONES['warm']).' '
            .(self::LENGTHS[$agent->setting('verbosity', 'balanced')] ?? self::LENGTHS['balanced'])
            ." Never use lists, markdown or emojis, and say numbers, dates and times the way people speak them. If you don't know something, say so and offer to take a message; never make things up.";
        if ($facts = trim(self::fill($agent, (string) $agent->setting('knowledge', ''), $variables))) {
            $parts[] = "What you know about the business:\n{$facts}";
        }
        $faqs = collect($agent->setting('faqs', []))->filter(fn ($f) => trim((string) ($f['q'] ?? '')) !== '' && trim((string) ($f['a'] ?? '')) !== '');
        if ($faqs->isNotEmpty()) {
            $parts[] = "Answers to common questions:\n".$faqs->map(fn ($f) => 'Q: '.trim($f['q'])."\nA: ".trim($f['a']))->implode("\n");
        }
        $parts[] = 'When the conversation is over (the caller says goodbye, or has nothing else and you have wrapped up), say one short goodbye and then hang up with the tool that ends the call. Never keep the line open after goodbye.';
        if ($agent->bookingEnabled()) {
            $hours = trim((string) $agent->setting('booking.hours', ''));
            $services = trim((string) $agent->setting('booking.services', ''));
            $parts[] = 'Booking: '.($services !== '' ? "you can book these: {$services}. " : '')
                .'Appointments last about '.((int) $agent->setting('booking.duration', 30) ?: 30).' minutes. When the caller wants an appointment, collect their full name, phone number'
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

    /** Lets the model hang up (browser test calls; Vapi and ElevenLabs have their own end-call tools). */
    public static function endCallTool(): array
    {
        return ['name' => 'end_call', 'description' => 'Hang up the call. Use it right after your goodbye, once the caller has nothing else.', 'parameters' => ['type' => 'object', 'properties' => ['reason' => ['type' => 'string', 'description' => 'Why the call is ending, e.g. the caller said goodbye']]]];
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
