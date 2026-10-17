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
    public const NODE_DATA_KEYS = ['integration', 'action', 'trigger', 'config', 'credential_id', 'label', 'notes', 'disabled', 'retries', 'on_error'];

    /** Keep only what the engine needs; React Flow adds UI state (selected, measured, dragging…). */
    public static function normalize(array $graph): array
    {
        $nodes = array_map(fn (array $node) => [
            'id' => $node['id'],
            'type' => $node['type'],
            'position' => ['x' => round((float) $node['position']['x'], 1), 'y' => round((float) $node['position']['y'], 1)],
            'data' => self::stepSettings(Arr::only($node['data'] ?? [], self::NODE_DATA_KEYS), $node['type']),
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
        // Several triggers may each start their own flow; the main one ("trigger") is always there.
        $triggers = array_column(array_filter($nodes, fn ($node) => $node['type'] === 'trigger'), 'id');
        if (! in_array(Workflow::TRIGGER_NODE_ID, $triggers, true)) {
            $fail($triggers === [] ? 'A workflow needs a trigger.' : 'The main trigger is missing.');
        }
        foreach ($triggers as $id) {
            if (! preg_match('/^'.Workflow::TRIGGER_NODE_ID.'(_\d+)?$/', $id)) {
                $fail('Triggers are named "trigger", "trigger_2", "trigger_3"…'); // the webhook URL of a trigger ends with its id
            }
        }
        foreach ($edges as $edge) {
            if (! in_array($edge['source'], $ids, true) || ! in_array($edge['target'], $ids, true)) {
                $fail('A connection points to a node that no longer exists.');
            }
            if (in_array($edge['target'], $triggers, true)) {
                $fail('Nothing can connect into a trigger.');
            }
        }

        try {
            app(TopologicalSorter::class)->sort($ids, $edges);
        } catch (RuntimeException $e) {
            $fail($e->getMessage());
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * A step's run settings, kept only when they differ from the default: disabled (skipped, the path goes on),
     * retries (0-5 quick tries again on temporary errors) and on_error ("continue" keeps the run going).
     */
    private static function stepSettings(array $data, string $type): array
    {
        $settings = Arr::only($data, ['disabled', 'retries', 'on_error']);
        $data = Arr::except($data, ['disabled', 'retries', 'on_error']);
        if ($type === 'trigger') {
            return $data;
        }

        return $data + array_filter([
            'disabled' => filter_var($settings['disabled'] ?? false, FILTER_VALIDATE_BOOLEAN) ?: null,
            'retries' => max(0, min(5, (int) ($settings['retries'] ?? 0))) ?: null,
            'on_error' => ($settings['on_error'] ?? null) === 'continue' ? 'continue' : null,
        ]);
    }

    public static function assertActivatable(Workflow $workflow): void
    {
        foreach ($workflow->triggerNodes() as $node) {
            $spec = $workflow->triggerSpec($node['id']);
            $name = count($workflow->triggerNodes()) > 1 ? ' "'.($node['data']['label'] ?? $node['id']).'"' : '';

            if (! $spec) {
                throw ValidationException::withMessages(['is_active' => "Choose a trigger{$name} before activating."]);
            }
            if ($spec['type'] === 'schedule' && ! CronExpression::isValidExpression(trim((string) ($workflow->triggerConfig($node['id'])['cron'] ?? '')))) {
                throw ValidationException::withMessages(['is_active' => "The schedule{$name} needs a valid cron expression."]);
            }
        }
    }
}
