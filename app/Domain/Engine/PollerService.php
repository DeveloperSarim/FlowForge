<?php

namespace App\Domain\Engine;

use App\Domain\Engine\Jobs\PollWorkflow;
use App\Domain\Integrations\WorkflowActionExecutor;
use App\Domain\System\SystemSettings;
use App\Domain\Workflows\Models\Workflow;
use Carbon\CarbonInterface;
use Cron\CronExpression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Time-based triggers, driven once a minute by `schedule:run`:
 * - schedule: cron expression on the trigger node → enqueue a run when due
 * - polling: call the manifest's polling endpoint every N minutes, enqueue one run per unseen item
 *
 * The trigger state, the new runs and their queue jobs are written in one transaction on the workflow's
 * locked row (the queue is the database driver on the same connection): a crash part-way writes nothing,
 * so an item is picked up again on the next poll instead of being lost, and two overlapping schedule:run
 * calls can't both fire the same minute.
 */
class PollerService
{
    private const SEEN_LIMIT = 1000;

    public function __construct(private WorkflowActionExecutor $executor) {}

    public function dispatchDue(?CarbonInterface $now = null): array
    {
        $now ??= now();

        return [
            'scheduled' => $this->dispatchSchedules($now),
            'polls' => $this->dispatchPolls($now),
        ];
    }

    public function dispatchSchedules(CarbonInterface $now): int
    {
        $local = $now->copy()->setTimezone(app(SystemSettings::class)->timezone());
        $minute = $local->format('Y-m-d H:i');
        $count = 0;

        Workflow::query()->active()->where('trigger_type', 'schedule')->each(function (Workflow $workflow) use ($local, $minute, &$count) {
            $cron = trim((string) ($workflow->triggerConfig()['cron'] ?? ''));

            if (! CronExpression::isValidExpression($cron) || ! (new CronExpression($cron))->isDue($local)) {
                return;
            }

            $count += $this->locked($workflow, function (Workflow $workflow) use ($local, $minute, $cron) {
                // A second schedule:run in the same minute must not fire the workflow twice.
                if (($workflow->trigger_state['last_scheduled_minute'] ?? null) === $minute) {
                    return 0;
                }
                $workflow->mergeTriggerState(['last_scheduled_minute' => $minute]);
                $workflow->start('schedule', ['data' => ['timestamp' => $local->toIso8601String(), 'cron' => $cron]]);

                return 1;
            });
        });

        return $count;
    }

    public function dispatchPolls(CarbonInterface $now): int
    {
        $count = 0;

        Workflow::query()->active()->where('trigger_type', 'polling')->each(function (Workflow $workflow) use ($now, &$count) {
            $count += $this->locked($workflow, function (Workflow $workflow) use ($now) {
                $interval = max(1, (int) ($workflow->triggerConfig()['poll_interval'] ?? 5));
                $last = $workflow->trigger_state['last_polled_at'] ?? null;

                // 10s of slack so cron jitter doesn't push every poll a full minute late.
                if ($last && Carbon::parse($last)->addMinutes($interval)->subSeconds(10)->isAfter($now)) {
                    return 0;
                }
                $workflow->mergeTriggerState(['last_polled_at' => $now->toIso8601String()]);
                PollWorkflow::dispatch($workflow->id);

                return 1;
            });
        });

        return $count;
    }

    /**
     * Run one poll: fetch, diff against the remembered item ids, start a run per new item (oldest first).
     * The very first poll only records a baseline so enabling a workflow doesn't replay history.
     */
    public function poll(Workflow $workflow): int
    {
        $idField = $workflow->triggerSpec()['id_field'] ?? 'id';
        $items = $this->fetch($workflow);
        $ids = array_map(fn ($item) => (string) (data_get($item, $idField) ?? md5(json_encode($item))), $items);

        // Remembering the items and starting their runs is one transaction: all of it happens, or none.
        return $this->locked($workflow, function (Workflow $workflow) use ($items, $ids) {
            $seen = $workflow->trigger_state['seen'] ?? null;
            $fresh = [];
            if (is_array($seen)) {
                $known = array_flip($seen);
                foreach ($items as $i => $item) {
                    if (! isset($known[$ids[$i]])) {
                        $fresh[] = $item;
                    }
                }
            }

            $workflow->mergeTriggerState([
                'seen' => array_slice(array_values(array_unique(array_merge($ids, $seen ?? []))), 0, self::SEEN_LIMIT),
            ]);
            foreach (array_reverse($fresh) as $item) {
                $workflow->start('polling', ['data' => $item]);
            }

            return count($fresh);
        });
    }

    /** Run $fn on a fresh, row-locked copy of the workflow inside a transaction; the state it reads is current. */
    private function locked(Workflow $workflow, callable $fn): int
    {
        return DB::transaction(function () use ($workflow, $fn) {
            $current = Workflow::query()->lockForUpdate()->find($workflow->id);

            return $current ? $fn($current) : 0;
        });
    }

    /** Call the trigger's polling endpoint and return the list of items it yields. */
    public function fetch(Workflow $workflow): array
    {
        $data = $workflow->triggerNode()['data'] ?? [];
        $integration = $this->executor->integration($data['integration'] ?? '');
        $trigger = collect($integration['triggers'])->firstWhere('key', $data['trigger'] ?? '');

        if (($trigger['type'] ?? null) !== 'polling') {
            throw new RuntimeException('This workflow does not use a polling trigger.');
        }

        $result = $this->executor->run($integration, $trigger, $data, [
            'now' => now()->toIso8601String(),
            'workflow' => ['id' => $workflow->id, 'name' => $workflow->name],
        ]);

        $items = ($trigger['items_path'] ?? '') !== '' ? data_get($result->output, $trigger['items_path']) : $result->output;

        if (! is_array($items)) {
            return [];
        }

        return array_is_list($items) ? $items : [$items];
    }
}
