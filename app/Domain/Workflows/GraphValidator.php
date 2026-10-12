<?php

namespace App\Domain\Workflows;

use App\Domain\Engine\TopologicalSorter;
use App\Domain\Workflows\Models\Workflow;
use Cron\CronExpression;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Structural rules every saved graph must follow (used by the editor API and the AI assistant).
 */
class GraphValidator
{
    public const NODE_DATA_KEYS = ['integration', 'action', 'trigger', 'config', 'credential_id', 'label', 'notes'];

    /** Keep only what the engine needs; React Flow adds UI state (selected, measured, dragging…). */
    public static function normalize(array $graph): array
    {
        $nodes = array_map(fn (array $node) => [
            'id' => $node['id'],
            'type' => $node['type'],
            'position' => ['x' => round((float) $node['position']['x'], 1), 'y' => round((float) $node['position']['y'], 1)],
            'data' => Arr::only($node['data'] ?? [], self::NODE_DATA_KEYS),
        ], $graph['nodes']);

        $edges = array_map(fn (array $edge) => [
            'id' => $edge['id'] ?? "{$edge['source']}-{$edge['target']}-".($edge['sourceHandle'] ?? 'out'),
            'source' => $edge['source'],
            'target' => $edge['target'],
            'sourceHandle' => $edge['sourceHandle'] ?? null,
        ], $graph['edges'] ?? []);

        $fail = fn (string $message) => throw ValidationException::withMessages(['graph' => $message]);
        $ids = array_column($nodes, 'id');

        if (count($ids) !== count(array_unique($ids))) {
            $fail('Node ids must be unique.');
        }
        if (count(array_filter($nodes, fn ($node) => $node['type'] === 'trigger')) !== 1) {
            $fail('A workflow needs exactly one trigger.');
        }
        $triggerId = collect($nodes)->firstWhere('type', 'trigger')['id'];
        foreach ($edges as $edge) {
            if (! in_array($edge['source'], $ids, true) || ! in_array($edge['target'], $ids, true)) {
                $fail('A connection points to a node that no longer exists.');
            }
            if ($edge['target'] === $triggerId) {
                $fail('Nothing can connect into the trigger.');
            }
        }

        try {
            app(TopologicalSorter::class)->sort($ids, $edges);
        } catch (RuntimeException $e) {
            $fail($e->getMessage());
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    public static function assertActivatable(Workflow $workflow): void
    {
        $spec = $workflow->triggerSpec();

        if (! $spec) {
            throw ValidationException::withMessages(['is_active' => 'Choose a trigger before activating.']);
        }
        if ($spec['type'] === 'schedule' && ! CronExpression::isValidExpression(trim((string) ($workflow->triggerConfig()['cron'] ?? '')))) {
            throw ValidationException::withMessages(['is_active' => 'The schedule needs a valid cron expression.']);
        }
    }
}
