<?php

namespace App\Domain\Integrations\Handlers;

use App\Domain\Integrations\ActionException;
use App\Domain\Integrations\ActionResult;
use App\Domain\Integrations\Models\Credential;
use App\Domain\Voice\Models\VoiceAgent;
use App\Domain\Voice\VoiceService;
use RuntimeException;

/** "Call a phone number": one of your voice agents places an outbound call. */
class VoiceHandler
{
    public function __construct(private VoiceService $voice) {}

    public function handle(string $handler, array $inputs, ?Credential $credential = null): ActionResult
    {
        $agent = VoiceAgent::query()->find((int) ($inputs['agent_id'] ?? 0));
        if (! $agent) {
            throw new ActionException('Choose the voice agent that makes the call.');
        }
        try {
            $call = $this->voice->call($agent, (string) ($inputs['to'] ?? ''), (array) ($inputs['variables'] ?? []));
        } catch (RuntimeException $e) {
            throw new ActionException($e->getMessage());
        }

        return new ActionResult(['call_id' => $call->id, 'status' => $call->status, 'agent' => $agent->name, 'to' => $call->to_number,
            'url' => url("/voice/calls?open={$call->id}")]);
    }
}
