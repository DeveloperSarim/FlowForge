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
        // Claim the retry in one UPDATE: of two workers (or a click on Retry at the same moment), one gets it.
        // Gone, already retried by hand, or no longer waiting: nothing to claim.
        $claimed = $failed && WorkflowExecution::query()->whereKey($failed->id)->whereNotNull('retry_at')->update(['retry_at' => null]) === 1;
        if (! $claimed || ! $failed->workflow?->is_active || WorkflowExecution::query()->where('retry_of', $failed->id)->exists()) {
            return; // switched off meanwhile: don't run it
        }

        $failed->workflow->resume($failed, 'auto_retry');
    }
}
