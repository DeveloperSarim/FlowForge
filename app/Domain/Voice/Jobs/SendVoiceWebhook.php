<?php

namespace App\Domain\Voice\Jobs;

use App\Domain\Voice\Models\VoiceAgent;
use App\Domain\Voice\VoiceWebhooks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/** One webhook delivery. A failure is tried again after 1, 5 and 30 minutes. */
class SendVoiceWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public array $backoff = [60, 300, 1800];

    public function __construct(public int $agentId, public string $url, public string $event, public array $data) {}

    public function handle(VoiceWebhooks $webhooks): void
    {
        $agent = VoiceAgent::query()->find($this->agentId);
        if (! $agent) {
            return;
        }
        [, $error] = $webhooks->send($agent, $this->url, $this->event, $this->data);
        if ($error !== null) {
            throw new RuntimeException("Webhook to {$this->url} failed: {$error}");
        }
    }
}
