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

    public function __construct(public int $workflowId) {}

    public function handle(PollerService $poller): void
    {
        $workflow = Workflow::query()->find($this->workflowId);

        if (! $workflow?->is_active || $workflow->trigger_type !== 'polling') {
            return;
        }

        try {
            $poller->poll($workflow);
            $workflow->mergeTriggerState(['last_error' => null]);
        } catch (Throwable $e) {
            $workflow->mergeTriggerState(['last_error' => $e->getMessage()]);
            rescue(fn () => app(Notifier::class)->pollFailed($workflow, $e->getMessage()));
            report($e);
        }
    }
}
