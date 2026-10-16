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
     * Retry a failed run later, from the failed step: after 2, 10, 60, 180 and 360 minutes. By default only
     * failures on temporary errors (app down, timeout, rate limit); a workflow can retry every failure.
     * Returns false when it won't be retried.
     */
    public static function scheduleRetry(WorkflowExecution $failed): bool
    {
        $workflow = $failed->workflow;
        $step = collect($failed->steps ?? [])->first(fn ($s) => ($s['status'] ?? null) === 'failed' && empty($s['continued']));
        $attempt = $failed->attempt ?? 1;
        // A step that may have gone through is never repeated automatically, not even with "retry every failure".
        if (! $workflow?->autoRetries() || ! in_array($failed->mode, Workflow::RETRYABLE_MODES, true) || ! $step || ! empty($step['uncertain'])
            || (empty($step['temporary']) && $workflow->retryOn() !== 'all') || $attempt > count(Workflow::RETRY_DELAYS)) {
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

    /**
     * The job itself died (the worker was killed, a timeout, an engine bug). The step that was in flight
     * (GraphRunner saves it before calling out) may or may not have reached the app, so it says so.
     */
    public function failed(?Throwable $e): void
    {
        $execution = WorkflowExecution::query()->find($this->executionId);
        if (! $execution || ! in_array($execution->status, [WorkflowExecution::PENDING, WorkflowExecution::RUNNING], true)) {
            return;
        }

        $error = 'Engine error: '.($e?->getMessage() ?? 'job failed');
        $steps = $execution->steps;
        foreach ($steps ?? [] as $i => $step) {
            if (($step['status'] ?? null) === 'running') {
                $steps[$i] = array_merge($step, ['status' => 'failed', 'uncertain' => true,
                    'error' => 'The run stopped while this step was running, so it may or may not have gone through. Check the app before running it again.']);
                $error = ($step['label'] ?? $step['node_id']).': '.$steps[$i]['error'];
            }
        }

        $execution->forceFill(['status' => WorkflowExecution::FAILED, 'error' => $error, 'steps' => $steps, 'finished_at' => now()])->save();
        self::afterFailure($execution);
    }
}
