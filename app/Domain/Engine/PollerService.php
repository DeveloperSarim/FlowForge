<?php

namespace App\Domain\Engine;

use App\Domain\Engine\Jobs\PollWorkflow;
use App\Domain\Integrations\WorkflowActionExecutor;
use App\Domain\System\SystemSettings;
use App\Domain\Workflows\Models\Workflow;
use Carbon\CarbonInterface;
use Cron\CronExpression;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Time-based triggers, driven once a minute by `schedule:run`:
 * - schedule: cron expression on the trigger node → enqueue a run when due
 * - polling: call the manifest's polling endpoint every N minutes, enqueue one run per unseen item
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
            // Guard against a second schedule:run in the same minute firing the workflow twice.
            if (($workflow->trigger_state['last_scheduled_minute'] ?? null) === $minute) {
                return;
            }

            $workflow->mergeTriggerState(['last_scheduled_minute' => $minute]);
            $workflow->start('schedule', ['data' => ['timestamp' => $local->toIso8601String(), 'cron' => $cron]]);
            $count++;
        });

        return $count;
    }

    public function dispatchPolls(CarbonInterface $now): int
    {
        $count = 0;

        Workflow::query()->active()->where('trigger_type', 'polling')->each(function (Workflow $workflow) use ($now, &$count) {
            $interval = max(1, (int) ($workflow->triggerConfig()['poll_interval'] ?? 5));
            $last = $workflow->trigger_state['last_polled_at'] ?? null;

            // 10s of slack so cron jitter doesn't push every poll a full minute late.
            if ($last && Carbon::parse($last)->addMinutes($interval)->subSeconds(10)->isAfter($now)) {
                return;
            }

            $workflow->mergeTriggerState(['last_polled_at' => $now->toIso8601String()]);
            PollWorkflow::dispatch($workflow->id);
            $count++;
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

        // Persist before starting runs: a crash here should miss an item, never duplicate one.
        $workflow->mergeTriggerState([
            'seen' => array_slice(array_values(array_unique(array_merge($ids, $seen ?? []))), 0, self::SEEN_LIMIT),
        ]);

        foreach (array_reverse($fresh) as $item) {
            $workflow->start('polling', ['data' => $item]);
        }

        return count($fresh);
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
