<?php

namespace App\Domain\Engine;

use App\Domain\Integrations\ActionException;
use App\Domain\Integrations\Models\Credential;
use App\Domain\Integrations\WorkflowActionExecutor;
use App\Domain\Workflows\Models\WorkflowExecution;
use RuntimeException;

/**
 * Walks a workflow DAG in topological order. A node runs when at least one incoming edge is
 * "live": its source ran and emitted that edge's handle (branches emit "true"/"false", filters
 * that fail emit nothing). Everything else is recorded as skipped. The first failure stops the run.
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

            $started = microtime(true);
            try {
                $result = $this->executor->execute($data, $context);
                $context['steps'][$id] = $result->output;
                if (! $result->halt) {
                    $emitted[$id] = $result->handle;
                }
                $steps[] = $step + [
                    'status' => 'success',
                    'input' => $result->input,
                    'output' => $result->output,
                    'http_status' => $result->httpStatus,
                    'request' => $result->request,
                    'handle' => $result->handle,
                    'halted' => $result->halt,
                    'duration_ms' => $this->elapsed($started),
                ];
            } catch (ActionException $e) {
                // ponytail: first failure stops the run; add a per-node "continue on fail" / retry flag if workflows need it.
                $error = "{$step['label']}: {$e->getMessage()}";
                $steps[] = $step + [
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                    'input' => $e->input,
                    'output' => $e->output,
                    'http_status' => $e->httpStatus,
                    'request' => $e->request,
                    'duration_ms' => $this->elapsed($started),
                ];
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
