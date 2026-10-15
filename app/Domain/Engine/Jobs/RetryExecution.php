<?php

namespace App\Domain\Engine\Jobs;

use App\Domain\Workflows\Models\WorkflowExecution;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** The automatic retry of a failed run, when its time comes (the cron worker picks delayed jobs up). */
class RetryExecution implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $executionId) {}

    public function handle(): void
    {
        $failed = WorkflowExecution::query()->with('workflow')->find($this->executionId);
        // Gone, already retried by hand, or no longer waiting.
        if (! $failed?->retry_at || WorkflowExecution::query()->where('retry_of', $failed->id)->exists()) {
            return;
        }
        if (! $failed->workflow?->is_active) {
            $failed->forceFill(['retry_at' => null])->save(); // switched off meanwhile: don't run it

            return;
        }

        $failed->workflow->resume($failed, 'auto_retry');
    }
}
