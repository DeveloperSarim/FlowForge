<?php

namespace App\Domain\Engine\Jobs;

use App\Domain\Engine\GraphRunner;
use App\Domain\System\Notifier;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowExecution;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunWorkflow implements ShouldQueue
{
    use Queueable;

    /** Workflows have side effects (emails, API writes): never retry automatically. */
    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $executionId, public ?array $only = null) {}

    public function handle(GraphRunner $runner): void
    {
        $execution = WorkflowExecution::query()->with('workflow')->find($this->executionId);

        if ($execution && $execution->status === WorkflowExecution::PENDING) {
            $runner->run($execution, $this->only);
            if ($execution->status === WorkflowExecution::FAILED && ! self::scheduleRetry($execution)) {
                self::afterFailure($execution); // the failure email and error workflows wait until the last try
            }
        }
    }

    /**
     * Retry a run that failed on a temporary error (app down, timeout, rate limit) later, from the failed step:
     * after 2, 10, 60, 180 and 360 minutes. Returns false when it won't be retried.
     */
    public static function scheduleRetry(WorkflowExecution $failed): bool
    {
        $workflow = $failed->workflow;
        $step = collect($failed->steps ?? [])->first(fn ($s) => ($s['status'] ?? null) === 'failed' && empty($s['continued']));
        $attempt = $failed->attempt ?? 1;
        if (! $workflow?->autoRetries() || ! in_array($failed->mode, Workflow::RETRYABLE_MODES, true)
            || empty($step['temporary']) || $attempt > count(Workflow::RETRY_DELAYS)) {
            return false;
        }

        $at = now()->addMinutes(Workflow::RETRY_DELAYS[$attempt - 1]);
        $failed->forceFill(['retry_at' => $at])->save();
        RetryExecution::dispatch($failed->id)->delay($at);

        return true;
    }

    /** Email the admin and start any "Error Trigger" workflows. Never throws. */
    public static function afterFailure(WorkflowExecution $execution): void
    {
        rescue(fn () => app(Notifier::class)->workflowFailed($execution));
        rescue(fn () => $execution->workflow?->startErrorWorkflows($execution));
    }

    public function failed(?Throwable $e): void
    {
        WorkflowExecution::query()
            ->whereKey($this->executionId)
            ->whereIn('status', [WorkflowExecution::PENDING, WorkflowExecution::RUNNING])
            ->update([
                'status' => WorkflowExecution::FAILED,
                'error' => 'Engine error: '.($e?->getMessage() ?? 'job failed'),
                'finished_at' => now(),
            ]);

        if ($execution = WorkflowExecution::query()->find($this->executionId)) {
            self::afterFailure($execution);
        }
    }
}
