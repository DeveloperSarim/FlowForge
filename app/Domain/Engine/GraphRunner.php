<?php

namespace App\Domain\Engine;

use App\Domain\Integrations\ActionException;
use App\Domain\Integrations\Models\Credential;
use App\Domain\Integrations\WorkflowActionExecutor;
use App\Domain\Workflows\Models\WorkflowExecution;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Walks a workflow DAG in topological order. A node runs when at least one incoming edge is
 * "live": its source ran and emitted that edge's handle (branches emit "true"/"false", filters
 * that fail emit nothing). Everything else is recorded as skipped.
 *
 * Per step: "disabled" skips it and the path goes on; "retries" tries it again (with a short wait) when
 * the failure is temporary; "on_error": "continue" records the failure and goes on. Otherwise the first
 * failure stops the run. A run that resumes a failed one (retry_of) reuses the steps that already
 * succeeded instead of running them again, so emails and API writes aren't repeated.
 */
class GraphRunner
{
    public function __construct(private WorkflowActionExecutor $executor, private TopologicalSorter $sorter) {}

    /**
     * @param  list<string>|null  $only  run just these nodes (editor "Execute step": the target and its ancestors)
     */
    public function run(WorkflowExecution $execution, ?array $only = null): WorkflowExecution
    {
        $workflow = $execution->workflow;
        $nodes = collect($workflow->graph['nodes'] ?? [])->keyBy('id');
        $edges = $workflow->graph['edges'] ?? [];
        $clock = microtime(true);

        $execution->forceFill(['status' => WorkflowExecution::RUNNING, 'started_at' => now()])->save();

        $context = [
            'steps' => [],
            'workflow' => ['id' => $workflow->id, 'name' => $workflow->name],
            'execution' => ['id' => $execution->id, 'mode' => $execution->mode],
            'now' => now()->toIso8601String(),
        ];
        $secrets = $this->secrets($nodes->all());
        $reuse = $this->reusable($execution);
        $emitted = [];
        $steps = [];
        $error = null;

        try {
            $order = $this->sorter->sort($nodes->keys()->all(), $edges);
        } catch (RuntimeException $e) {
            $order = [];
            $error = $e->getMessage();
        }

        foreach ($order as $id) {
            $node = $nodes[$id];
            $data = $node['data'] ?? [];
            $step = [
                'node_id' => $id,
                'type' => $node['type'] ?? 'action',
                'label' => $data['label'] ?? $id,
                'integration' => $data['integration'] ?? null,
                'operation' => $data['action'] ?? $data['trigger'] ?? null,
            ];

            if ($step['type'] === 'trigger') {
                $context['steps'][$id] = $execution->trigger_payload ?? [];
                $emitted[$id] = null;
                $steps[] = $step + ['status' => 'success', 'output' => $context['steps'][$id], 'duration_ms' => 0];

                continue;
            }

            if ($error !== null || ($only !== null && ! in_array($id, $only, true)) || ! $this->isReached($id, $edges, $emitted)) {
                $steps[] = $step + ['status' => 'skipped'];

                continue;
            }

            if (! empty($data['disabled'])) {
                // Turned off: skipped, and the path continues (an If/Else takes its "true" branch).
                $emitted[$id] = $step['type'] === 'branch' ? 'true' : null;
                $steps[] = $step + ['status' => 'skipped', 'disabled' => true];

                continue;
            }

            if (isset($reuse[$id])) {
                $context['steps'][$id] = $reuse[$id]['output'] ?? [];
                if (empty($reuse[$id]['halted'])) {
                    $emitted[$id] = $reuse[$id]['handle'] ?? null;
                }
                $steps[] = ['reused' => true] + $reuse[$id];

                continue;
            }

            $started = microtime(true);
            $tries = (int) ($data['retries'] ?? 0);
            for ($attempt = 0; ; $attempt++) {
                try {
                    $result = $this->executor->execute($data, $context);
                    $context['steps'][$id] = $result->output;
                    if (! $result->halt) {
                        $emitted[$id] = $result->handle;
                    }
                    $steps[] = $step + array_filter([
                        'status' => 'success',
                        'input' => $result->input,
                        'output' => $result->output,
                        'http_status' => $result->httpStatus,
                        'request' => $result->request,
                        'handle' => $result->handle,
                        'halted' => $result->halt,
                        'tries' => $attempt ? $attempt + 1 : null,
                        'duration_ms' => $this->elapsed($started),
                    ], fn ($v) => $v !== null);
                    break;
                } catch (ActionException $e) {
                    if ($attempt < $tries && $e->isTemporary()) {
                        Sleep::for(min(30, 2 ** ($attempt + 1)))->seconds(); // 2, 4, 8, 16, 30 s

                        continue;
                    }
                    $failed = $step + array_filter([
                        'status' => 'failed',
                        'error' => $e->getMessage(),
                        'input' => $e->input,
                        'output' => $e->output,
                        'http_status' => $e->httpStatus,
                        'request' => $e->request,
                        'temporary' => $e->isTemporary() ?: null,
                        'tries' => $attempt ? $attempt + 1 : null,
                        'duration_ms' => $this->elapsed($started),
                    ], fn ($v) => $v !== null);
                    if (($data['on_error'] ?? null) === 'continue') {
                        // Later steps run and can read what went wrong from {{ steps.<id>.error }}.
                        $context['steps'][$id] = ['error' => $e->getMessage(), 'http_status' => $e->httpStatus];
                        $emitted[$id] = $step['type'] === 'branch' ? 'false' : null;
                        $steps[] = $failed + ['continued' => true];
                    } else {
                        $error = "{$step['label']}: {$e->getMessage()}";
                        $steps[] = $failed;
                    }
                    break;
                }
            }
        }

        $execution->forceFill([
            'status' => $error === null ? WorkflowExecution::COMPLETED : WorkflowExecution::FAILED,
            'error' => $error === null ? null : $this->mask($error, $secrets),
            'steps' => array_map(fn (array $step) => $this->forStorage($step, $secrets), $steps),
            'finished_at' => now(),
            'duration_ms' => $this->elapsed($clock),
        ])->save();

        return $execution;
    }

    /**
     * Steps of the failed run this one retries that succeeded there, by node id: they are reused, not run again.
     * Nothing is reused when an output was too big to keep in full (it couldn't be passed on faithfully).
     */
    private function reusable(WorkflowExecution $execution): array
    {
        $previous = $execution->retry_of ? WorkflowExecution::query()->find($execution->retry_of) : null;
        $done = collect($previous?->steps ?? [])->filter(fn ($s) => ($s['status'] ?? null) === 'success' && ($s['type'] ?? null) !== 'trigger');
        if ($done->contains(fn ($s) => ! empty($s['output']['_truncated']))) {
            return [];
        }

        return $done->keyBy('node_id')->all();
    }

    /** The node plus everything upstream of it. */
    public static function ancestorsOf(string $nodeId, array $edges): array
    {
        $result = [$nodeId];
        for ($i = 0; $i < count($result); $i++) {
            foreach ($edges as $edge) {
                if (($edge['target'] ?? null) === $result[$i] && ! in_array($edge['source'], $result, true)) {
                    $result[] = $edge['source'];
                }
            }
        }

        return $result;
    }

    private function isReached(string $id, array $edges, array $emitted): bool
    {
        foreach ($edges as $edge) {
            if (($edge['target'] ?? null) !== $id || ! array_key_exists($edge['source'] ?? '', $emitted)) {
                continue;
            }
            $handle = $emitted[$edge['source']];
            if ($handle === null || $handle === ($edge['sourceHandle'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /** Secret values of every credential this graph uses, longest first, for log masking. */
    private function secrets(array $nodes): array
    {
        $ids = array_filter(array_map(fn ($node) => $node['data']['credential_id'] ?? null, $nodes));
        if ($ids === []) {
            return [];
        }

        $secrets = Credential::query()->whereIn('id', $ids)->get()->flatMap->secrets()->unique()->all();
        usort($secrets, fn ($a, $b) => strlen($b) <=> strlen($a));

        return $secrets;
    }

    private function mask(string $text, array $secrets): string
    {
        foreach ($secrets as $secret) {
            $text = str_replace([$secret, substr(json_encode($secret), 1, -1)], '••••••', $text);
        }

        return $text;
    }

    /** Mask secrets and cap payload size so one huge response can't bloat the executions table. */
    private function forStorage(array $step, array $secrets): array
    {
        $max = config('flowforge.max_log_bytes');

        foreach (['input', 'output', 'request', 'error'] as $key) {
            if (! isset($step[$key])) {
                continue;
            }

            $json = $this->mask(json_encode($step[$key], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), $secrets);

            if (strlen($json) > $max) {
                $step[$key] = ['_truncated' => true, 'bytes' => strlen($json), 'preview' => mb_strcut($json, 0, $max)];
            } else {
                $decoded = json_decode($json, true);
                $step[$key] = json_last_error() === JSON_ERROR_NONE ? $decoded : $json;
            }
        }

        return $step;
    }

    private function elapsed(float $since): int
    {
        return (int) round((microtime(true) - $since) * 1000);
    }
}
