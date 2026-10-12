<?php

namespace App\Domain\Engine\Jobs;

use App\Domain\Engine\GraphRunner;
use App\Domain\System\Notifier;
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
            if ($execution->status === WorkflowExecution::FAILED) {
                self::afterFailure($execution);
            }
        }
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
