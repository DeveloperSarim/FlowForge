<?php

namespace App\Domain\Voice\Providers;

use App\Domain\Voice\Models\VoiceAgent;
use App\Domain\Voice\Models\VoiceCall;
use Carbon\CarbonInterface;

/**
 * A phone provider that runs the live call (telephony, speech, model) for a FlowForge voice agent.
 *
 * A normalised call is: {remote_id, direction, status, from, to, started_at, ended_at, duration, cost,
 * summary, outcome, transcript: [{role, text, at}], data, recording_url, ended_reason}.
 */
interface VoiceProvider
{
    /** Creates or updates the agent on the provider (prompt, voice, booking tool, analysis). */
    public function sync(VoiceAgent $agent): void;

    /** @return list<array{id: string, label: string}> the provider's phone numbers */
    public function phoneNumbers(VoiceAgent $agent): array;

    /** Points the agent's phone number at it, so inbound calls reach this agent. */
    public function attachNumber(VoiceAgent $agent): void;

    /** Starts an outbound call; returns the provider's call id. */
    public function call(VoiceAgent $agent, string $to, array $variables): string;

    /** @return list<array> normalised calls started after $since */
    public function recentCalls(VoiceAgent $agent, CarbonInterface $since): array;

    /** @return array{url?: string, body?: string, type?: string}|null the recording, as a link or the audio itself */
    public function recording(VoiceCall $call): ?array;
}
