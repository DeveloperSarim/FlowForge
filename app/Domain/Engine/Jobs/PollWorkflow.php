<?php

namespace App\Domain\Engine\Jobs;

use App\Domain\Engine\PollerService;
use App\Domain\System\Notifier;
use App\Domain\Workflows\Models\Workflow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PollWorkflow implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** @param  string|null  $trigger  which polling trigger (null: the main one) */
    public function __construct(public int $workflowId, public ?string $trigger = null) {}

    public function handle(PollerService $poller): void
    {
        $workflow = Workflow::query()->find($this->workflowId);

        if (! $workflow?->is_active || ($workflow->triggerSpec($this->trigger)['type'] ?? null) !== 'polling') {
            return;
        }

        try {
            $poller->poll($workflow, $this->trigger);
            $workflow->mergeTriggerState(['last_error' => null], $this->trigger);
        } catch (Throwable $e) {
            $workflow->mergeTriggerState(['last_error' => $e->getMessage()], $this->trigger);
            rescue(fn () => app(Notifier::class)->pollFailed($workflow, $e->getMessage()));
            report($e);
        }
    }
}
