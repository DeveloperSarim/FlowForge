<?php

namespace App\Domain\Workflows;

use App\Domain\Integrations\CustomApps;
use App\Domain\Integrations\ManifestLoader;
use App\Domain\Integrations\Models\Credential;
use App\Domain\Workflows\Models\Workflow;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Workflow files for moving workflows between installs or keeping copies. An export never carries
 * credentials or values typed into password fields; it does carry the custom apps the workflows use,
 * so they work on the other install too.
 */
class WorkflowPorter
{
    public const FORMAT = 'flowforge.workflows';

    private const NODE_TYPES = ['trigger', 'action', 'branch'];

    public function __construct(private ManifestLoader $manifests, private CustomApps $customApps) {}

    /** @param  iterable<Workflow>  $workflows */
    public function export(iterable $workflows): array
    {
        $items = $custom = [];
        foreach ($workflows as $workflow) {
            $nodes = [];
            foreach ($workflow->graph['nodes'] ?? [] as $node) {
                $data = $node['data'] ?? [];
                unset($data['credential_id']);
                $app = (string) ($data['integration'] ?? '');
                $op = isset($data['action']) ? $this->manifests->action($app, $data['action']) : $this->manifests->trigger($app, (string) ($data['trigger'] ?? ''));
                foreach ($op['inputs'] ?? [] as $input) {
                    if (($input['type'] ?? null) === 'password' && isset($data['config'][$input['key']])) {
                        $data['config'][$input['key']] = '';
                    }
                }
                if ($raw = $this->customApps->raw($app)) {
                    $custom[$app] = $raw;
                }
                $nodes[] = ['id' => $node['id'], 'type' => $node['type'], 'position' => $node['position'], 'data' => $data];
            }
            $items[] = array_filter(['name' => $workflow->name, 'description' => $workflow->description, 'settings' => $workflow->settings,
                'graph' => ['nodes' => $nodes, 'edges' => $workflow->graph['edges'] ?? []]], fn ($v) => $v !== null);
        }

        return [
            'format' => self::FORMAT,
            'version' => 1,
            'flowforge' => trim((string) @file_get_contents(base_path('VERSION'))),
            'exported_at' => now()->toIso8601String(),
            'workflows' => $items,
            'custom_apps' => array_values($custom),
        ];
    }

    /**
     * Creates the workflows in a FlowForge export, all switched off. Missing custom apps are added from the
     * file, and a step gets a credential when there is exactly one saved for its app.
     *
     * @return array{workflows: list<Workflow>, notes: list<string>}
     */
    public function import(array $document): array
    {
        // Older editor downloads were a bare {name, nodes, edges}.
        if (! isset($document['format']) && is_array($document['nodes'] ?? null) && is_array($document['edges'] ?? null)) {
            $document = ['format' => self::FORMAT, 'workflows' => [['name' => $document['name'] ?? null, 'graph' => ['nodes' => $document['nodes'], 'edges' => $document['edges']]]]];
        }
        if (($document['format'] ?? null) !== self::FORMAT || ! is_array($document['workflows'] ?? null)) {
            throw new InvalidArgumentException("That isn't a FlowForge workflow file. Export one from Workflows → ⋯ → Export.");
        }
        if ($document['workflows'] === [] || count($document['workflows']) > 200) {
            throw new InvalidArgumentException('The file needs between 1 and 200 workflows.');
        }

        $notes = [];
        foreach ((array) ($document['custom_apps'] ?? []) as $app) {
            $key = (string) ($app['key'] ?? '');
            if ($key === '' || $this->manifests->get($key)) {
                continue; // already on this install: keep the local version
            }
            try {
                $this->customApps->save($app);
                $notes[] = "Added the custom app {$app['name']}.";
            } catch (InvalidArgumentException $e) {
                $notes[] = 'Couldn\'t add the custom app '.($app['name'] ?? $key).": {$e->getMessage()}";
            }
        }

        $credentials = Credential::query()->get(['id', 'integration'])->groupBy('integration');
        $existing = Workflow::query()->pluck('name')->map(fn ($n) => Str::lower($n))->flip();
        $missing = $connect = $choose = [];

        $workflows = DB::transaction(fn () => collect($document['workflows'])->map(function ($item, $i) use ($credentials, &$existing, &$missing, &$connect, &$choose) {
            $name = Str::limit(trim((string) ($item['name'] ?? '')) ?: 'Imported workflow', 240, '');
            $nodes = [];
            foreach ((array) ($item['graph']['nodes'] ?? []) as $node) {
                if (! is_array($node) || ! in_array($node['type'] ?? null, self::NODE_TYPES, true) || ! is_string($node['id'] ?? null)) {
                    throw new InvalidArgumentException("\"{$name}\" has a step this version can't read.");
                }
                $data = array_intersect_key((array) ($node['data'] ?? []), array_flip(GraphValidator::NODE_DATA_KEYS));
                unset($data['credential_id']);
                $app = (string) ($data['integration'] ?? '');
                $manifest = $app !== '' ? $this->manifests->get($app) : null;
                $opKey = $data['action'] ?? $data['trigger'] ?? null;
                if ($app !== '' && (! $manifest || ($opKey && ! collect($manifest[isset($data['action']) ? 'actions' : 'triggers'])->contains('key', $opKey)))) {
                    $missing[$app] = $manifest['name'] ?? $app;
                } elseif ($manifest && array_diff(array_column($manifest['auth'], 'type'), ['none'])) {
                    $saved = $credentials->get($app, collect());
                    match ($saved->count()) {
                        0 => $connect[$app] = $manifest['name'],
                        1 => $data['credential_id'] = $saved->first()->id,
                        default => $choose[$app] = $manifest['name'],
                    };
                }
                $nodes[] = ['id' => $node['id'], 'type' => $node['type'], 'position' => ['x' => (float) ($node['position']['x'] ?? 0), 'y' => (float) ($node['position']['y'] ?? 0)], 'data' => $data];
            }
            if (count($nodes) > 300) {
                throw new InvalidArgumentException("\"{$name}\" has more than 300 steps.");
            }

            try {
                $graph = GraphValidator::normalize(['nodes' => $nodes, 'edges' => array_values(array_filter((array) ($item['graph']['edges'] ?? []), fn ($e) => is_array($e) && is_string($e['source'] ?? null) && is_string($e['target'] ?? null)))]);
            } catch (ValidationException $e) {
                throw new InvalidArgumentException("\"{$name}\": ".collect($e->errors())->flatten()->first());
            }
            if (isset($existing[Str::lower($name)])) {
                $name .= ' (imported)';
            }
            $existing[Str::lower($name)] = true;

            $settings = isset($item['settings']['auto_retry']) ? ['auto_retry' => (bool) $item['settings']['auto_retry']] : null;

            return Workflow::query()->create(['name' => $name, 'description' => Str::limit((string) ($item['description'] ?? ''), 2000, '') ?: null, 'graph' => $graph, 'settings' => $settings]);
        })->all());

        if ($missing) {
            $notes[] = 'Not installed here: '.implode(', ', $missing).'. Those steps need the app before they can run.';
        }
        if ($connect) {
            $notes[] = 'Connect your account for '.implode(', ', $connect).' (Credentials).';
        }
        if ($choose) {
            $notes[] = 'Choose which account to use for '.implode(', ', $choose).' in each step.';
        }

        return ['workflows' => $workflows, 'notes' => $notes];
    }

    /** An n8n export can't be mapped node-for-node, but the AI builder can rebuild it from this outline. */
    public static function n8nOutline(array $document): ?string
    {
        if (! is_array($document['nodes'] ?? null) || ! array_key_exists('connections', $document)) {
            return null;
        }
        $steps = collect($document['nodes'])->filter(fn ($n) => is_array($n) && isset($n['type']))->map(function ($n) {
            $type = Str::of($n['type'])->afterLast('.')->headline();
            $what = collect(Arr::only((array) ($n['parameters'] ?? []), ['resource', 'operation', 'event', 'channel', 'url', 'method', 'path', 'rule']))
                ->map(fn ($v, $k) => $k.': '.Str::limit(is_scalar($v) ? (string) $v : json_encode($v), 60))->implode(', ');

            return '- '.($n['name'] ?? $type)." ({$type}".($what !== '' ? "; {$what}" : '').')';
        })->take(40)->implode("\n");

        return Str::limit('Rebuild this n8n workflow in FlowForge'.(isset($document['name']) ? " (\"{$document['name']}\")" : '').". Its nodes in order:\n{$steps}", 1800);
    }
}
