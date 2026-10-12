<?php

namespace App\Domain\Assistant;

use App\Domain\Assistant\Models\AiConversation;
use App\Domain\Engine\GraphRunner;
use App\Domain\Integrations\ManifestLoader;
use App\Domain\Integrations\Models\Credential;
use App\Domain\Workflows\GraphValidator;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowExecution;
use App\Domain\Workflows\TemplateLibrary;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * The tools the assistant can call. Outputs are deliberately terse (short keys, no nulls,
 * truncated payloads) because every byte comes back as input tokens on the next turn.
 */
class WorkflowTools
{
    public function __construct(private ManifestLoader $manifests) {}

    /** @return list<array{name: string, description: string, parameters: array}> */
    public function definitions(): array
    {
        $node = ['type' => 'object', 'properties' => [
            'id' => ['type' => 'string', 'description' => '"trigger" for the trigger node, otherwise step_1, step_2…'],
            'app' => ['type' => 'string', 'description' => 'Integration key from search_apps'],
            'op' => ['type' => 'string', 'description' => 'Trigger key (for id "trigger") or action key'],
            'label' => ['type' => 'string'],
            'config' => ['type' => 'object', 'description' => 'Input values keyed by input key'],
            'credential_id' => ['type' => 'integer'],
        ], 'required' => ['id', 'app', 'op']];
        $edge = ['type' => 'object', 'properties' => [
            'from' => ['type' => 'string'], 'to' => ['type' => 'string'],
            'branch' => ['type' => 'string', 'description' => '"true" or "false" when "from" is an If/Else node'],
        ], 'required' => ['from', 'to']];

        return [
            ['name' => 'search_apps', 'description' => 'Find integrations by name, purpose or action (e.g. "send sms", "google sheets"). Returns app keys with their action and trigger keys.',
                'parameters' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string'], 'limit' => ['type' => 'integer', 'description' => 'max 15, default 8']], 'required' => ['query']]],
            ['name' => 'get_app', 'description' => 'Input definitions of an app\'s actions/triggers. Format "key*:type=default" (* = required, select options in []). Ask only for the ops you need.',
                'parameters' => ['type' => 'object', 'properties' => ['app' => ['type' => 'string'], 'ops' => ['type' => 'array', 'items' => ['type' => 'string']]], 'required' => ['app']]],
            ['name' => 'list_workflows', 'description' => 'All workflows: id, name, active, trigger type, step count.',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass]],
            ['name' => 'get_workflow', 'description' => 'Full definition of one workflow (nodes with config, edges).',
                'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']]],
            ['name' => 'save_workflow', 'description' => 'Create (omit id) or replace (give id) a workflow. Send the complete node and edge list; positions are computed. Returns warnings to relay or fix.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'description' => ['type' => 'string'],
                    'nodes' => ['type' => 'array', 'items' => $node], 'edges' => ['type' => 'array', 'items' => $edge],
                ], 'required' => ['name', 'nodes', 'edges']]],
            ['name' => 'set_workflow_active', 'description' => 'Turn a workflow on (its trigger runs it in the background) or off.',
                'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'active' => ['type' => 'boolean']], 'required' => ['id', 'active']]],
            ['name' => 'delete_workflow', 'description' => 'Ask to delete a workflow. The user must confirm in the UI; nothing is deleted until then.',
                'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']]],
            ['name' => 'list_templates', 'description' => 'Ready-made workflows (key, name, apps). Prefer use_template when one fits the request — it is cheaper than building from scratch.',
                'parameters' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]]],
            ['name' => 'use_template', 'description' => 'Create a workflow from a template. Returns its id and what the user must fill in; adjust it afterwards with save_workflow if needed.',
                'parameters' => ['type' => 'object', 'properties' => ['key' => ['type' => 'string'], 'name' => ['type' => 'string']], 'required' => ['key']]],
            ['name' => 'list_credentials', 'description' => 'Saved credentials (id, name, app). Optionally filter by app key.',
                'parameters' => ['type' => 'object', 'properties' => ['app' => ['type' => 'string']]]],
            ['name' => 'test_workflow', 'description' => 'Run a workflow now with its test data and get a per-step result. This performs real actions (emails, messages, API writes): only use when the user asked, or the steps are read-only.',
                'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'node' => ['type' => 'string', 'description' => 'Only run up to this step']], 'required' => ['id']]],
            ['name' => 'get_execution', 'description' => 'Per-step result of a past run (to explain or fix a failure).',
                'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']]],
        ];
    }

    /**
     * @return array{0: mixed, 1: ?array, 2: bool} [tool output, UI action or null, is_error]
     */
    public function call(string $name, array $input, AiConversation $conversation): array
    {
        // The assistant acts with the signed-in person's access, never more.
        $needs = match ($name) {
            'search_apps', 'get_app' => null,
            'save_workflow', 'use_template', 'set_workflow_active', 'test_workflow' => 'workflows.edit',
            'delete_workflow' => 'workflows.delete',
            default => 'workflows.view',
        };
        if (in_array($name, ['test_workflow', 'set_workflow_active', 'delete_workflow'], true) && auth()->user()?->isDemo()) {
            return [['error' => 'Running, switching on and deleting workflows are turned off in the live demo. Tell the user to build and inspect; the full version does the rest.'], null, true];
        }
        if ($needs && ($user = auth()->user()) && ! $user->hasPermission($needs)) {
            return [['error' => 'This person\'s account doesn\'t have "'.User::PERMISSIONS[$needs].'" access. Tell them an admin can grant it under Settings → Team.'], null, true];
        }

        try {
            return match ($name) {
                'search_apps' => [$this->searchApps((string) ($input['query'] ?? ''), (int) ($input['limit'] ?? 8)), null, false],
                'get_app' => [$this->getApp((string) ($input['app'] ?? ''), (array) ($input['ops'] ?? [])), null, false],
                'list_workflows' => [$this->listWorkflows(), null, false],
                'get_workflow' => [$this->getWorkflow((int) ($input['id'] ?? 0)), null, false],
                'save_workflow' => $this->saveWorkflow($input, $conversation),
                'set_workflow_active' => $this->setActive((int) ($input['id'] ?? 0), (bool) ($input['active'] ?? false)),
                'delete_workflow' => $this->requestDelete((int) ($input['id'] ?? 0)),
                'list_credentials' => [$this->listCredentials($input['app'] ?? null), null, false],
                'list_templates' => [$this->listTemplates((string) ($input['query'] ?? '')), null, false],
                'use_template' => $this->useTemplate((string) ($input['key'] ?? ''), $input['name'] ?? null, $conversation),
                'test_workflow' => $this->testWorkflow((int) ($input['id'] ?? 0), $input['node'] ?? null),
                'get_execution' => [$this->summarize(WorkflowExecution::query()->findOrFail((int) ($input['id'] ?? 0))), null, false],
                default => [['error' => "Unknown tool $name"], null, true],
            };
        } catch (ValidationException $e) {
            return [['error' => implode(' ', $e->validator->errors()->all())], null, true];
        } catch (Throwable $e) {
            return [['error' => $e->getMessage()], null, true];
        }
    }

    private function searchApps(string $query, int $limit): array
    {
        $words = array_filter(preg_split('/\s+/', Str::lower(trim($query))));
        $limit = max(1, min(15, $limit));

        $scored = [];
        foreach ($this->manifests->catalog() as $app) {
            $haystack = Str::lower($app['key'].' '.$app['name'].' '.$app['category'].' '.$app['description'].' '
                .implode(' ', array_column($app['actions'], 'name')).' '.implode(' ', array_column($app['triggers'], 'name')));
            $score = 0;
            foreach ($words as $word) {
                $score += match (true) {
                    Str::lower($app['name']) === $word || $app['key'] === $word => 10,
                    str_contains(Str::lower($app['name']), $word) => 5,
                    str_contains($haystack, $word) => 1,
                    default => 0,
                };
            }
            if ($score > 0) {
                $scored[] = [$score + ($app['popular'] ? 0.5 : 0), $app];
            }
        }
        usort($scored, fn ($a, $b) => $b[0] <=> $a[0]);

        return array_map(fn ($pair) => array_filter([
            'key' => $pair[1]['key'],
            'name' => $pair[1]['name'],
            'actions' => implode(',', array_slice(array_column($pair[1]['actions'], 'key'), 0, 14)),
            'triggers' => implode(',', array_map(fn ($t) => "{$t['key']}({$t['type']})", $pair[1]['triggers'])),
            'auth' => $pair[1]['auth'] ? implode('|', $pair[1]['auth']) : null,
        ]), array_slice($scored, 0, $limit));
    }

    private function getApp(string $key, array $ops): array
    {
        $manifest = $this->manifests->get($key) ?? throw new InvalidArgumentException("No app [$key]. Use search_apps.");
        $format = fn (array $op) => array_filter([
            'name' => $op['name'],
            'desc' => isset($op['description']) ? Str::limit($op['description'], 110) : null,
            'type' => $op['type'] ?? null,
            'out' => isset($op['type']) ? null : $this->describeOutput($op),
            'branches' => isset($op['outputs']) ? implode('/', $op['outputs']) : null,
            'inputs' => array_map(function ($i) {
                $spec = $i['key'].(empty($i['required']) ? '' : '*').':'.$i['type'];
                if ($i['type'] === 'select') {
                    $spec .= '['.implode('|', array_slice(array_column($i['options'] ?? [], 'value'), 0, 12)).']';
                }
                if (isset($i['default']) && ! is_array($i['default'])) {
                    $spec .= '='.Str::limit((string) $i['default'], 40);
                }

                return $spec;
            }, $op['inputs']),
        ], fn ($v) => $v !== null && $v !== []);

        $result = ['app' => $key, 'auth' => implode('|', array_column($manifest['auth'], 'type'))];
        foreach (['actions', 'triggers'] as $kind) {
            foreach ($manifest[$kind] as $op) {
                if ($ops === [] || in_array($op['key'], $ops, true)) {
                    $result[$kind][$op['key']] = $format($op);
                }
            }
        }

        return $result;
    }

    private function listWorkflows(): array
    {
        return Workflow::query()->latest('updated_at')->limit(100)->get()->map(fn (Workflow $w) => [
            'id' => $w->id, 'name' => $w->name, 'active' => $w->is_active, 'trigger' => $w->trigger_type, 'steps' => count($w->graph['nodes'] ?? []),
        ])->all();
    }

    private function getWorkflow(int $id): array
    {
        $workflow = Workflow::query()->findOrFail($id);

        return [
            'id' => $workflow->id,
            'name' => $workflow->name,
            'description' => $workflow->description,
            'active' => $workflow->is_active,
            'nodes' => array_map(fn ($n) => array_filter([
                'id' => $n['id'],
                'app' => $n['data']['integration'] ?? null,
                'op' => $n['data']['trigger'] ?? $n['data']['action'] ?? null,
                'label' => $n['data']['label'] ?? null,
                'config' => $n['data']['config'] ?? null,
                'credential_id' => $n['data']['credential_id'] ?? null,
            ], fn ($v) => $v !== null && $v !== []), $workflow->graph['nodes'] ?? []),
            'edges' => array_map(fn ($e) => array_filter(['from' => $e['source'], 'to' => $e['target'], 'branch' => $e['sourceHandle'] ?? null]), $workflow->graph['edges'] ?? []),
        ];
    }

    private function saveWorkflow(array $input, AiConversation $conversation): array
    {
        $existing = isset($input['id']) ? Workflow::query()->findOrFail((int) $input['id']) : null;
        [$graph, $warnings] = $this->buildGraph((array) ($input['nodes'] ?? []), (array) ($input['edges'] ?? []), $existing);

        $workflow = $existing ?? new Workflow;
        $workflow->fill([
            'name' => Str::limit(trim((string) ($input['name'] ?? $existing?->name ?? 'New workflow')), 250, ''),
            'description' => $input['description'] ?? $existing?->description,
            'graph' => GraphValidator::normalize($graph),
        ]);
        if ($workflow->is_active) {
            GraphValidator::assertActivatable($workflow);
        }
        $workflow->save();

        if (! $conversation->workflow_id) {
            $conversation->workflow_id = $workflow->id;
        }

        return [
            array_filter(['id' => $workflow->id, 'url' => "/workflows/{$workflow->id}", 'steps' => count($graph['nodes']), 'warnings' => $warnings]),
            ['type' => $existing ? 'updated' : 'created', 'workflow_id' => $workflow->id, 'name' => $workflow->name],
            false,
        ];
    }

    /**
     * Turn the assistant's compact nodes/edges into an engine graph, checking every app, operation,
     * config key and credential. Hard problems throw (the model fixes them); soft ones become warnings.
     *
     * @return array{0: array, 1: list<string>}
     */
    public function buildGraph(array $nodes, array $edges, ?Workflow $existing): array
    {
        $previous = collect($existing?->graph['nodes'] ?? [])->keyBy('id');
        $credentials = Credential::query()->get(['id', 'name', 'integration', 'auth_type']);
        $warnings = $errors = [];
        $graphNodes = $ops = [];

        foreach ($nodes as $node) {
            $id = (string) ($node['id'] ?? '');
            $manifest = $this->manifests->get((string) ($node['app'] ?? ''));
            if ($id === '' || ! $manifest) {
                $errors[] = "Node [$id]: unknown app [".($node['app'] ?? '').'] — use search_apps.';

                continue;
            }

            $isTrigger = $id === Workflow::TRIGGER_NODE_ID;
            $operation = collect($manifest[$isTrigger ? 'triggers' : 'actions'])->firstWhere('key', $node['op'] ?? '');
            if (! $operation) {
                $valid = implode(', ', array_column($manifest[$isTrigger ? 'triggers' : 'actions'], 'key'));
                $errors[] = "Node [$id]: {$manifest['key']} has no ".($isTrigger ? 'trigger' : 'action')." [{$node['op']}]. Valid: {$valid}";

                continue;
            }

            $config = $node['config'] ?? [];
            if (is_string($config)) {
                $config = json_decode($config, true) ?? []; // Gemini sends objects as JSON strings
            }
            $declared = array_column($operation['inputs'], 'key');
            if ($unknown = array_diff(array_keys((array) $config), $declared)) {
                $errors[] = "Node [$id]: unknown config keys ".implode(', ', $unknown).' for '.$manifest['key'].'.'.$operation['key'].'. Valid: '.implode(', ', $declared);
            }
            foreach ($operation['inputs'] as $inputDef) {
                $value = $config[$inputDef['key']] ?? $inputDef['default'] ?? null;
                if (! empty($inputDef['required']) && ($value === null || $value === '' || $value === [])) {
                    $warnings[] = "$id ({$manifest['name']}): \"{$inputDef['label']}\" is empty — the user must fill it in.";
                }
            }

            $credentialId = isset($node['credential_id']) ? (int) $node['credential_id'] : null;
            $needsCredential = ! in_array('none', array_column($manifest['auth'], 'type'), true);
            if ($credentialId && ! $credentials->where('id', $credentialId)->where('integration', $manifest['key'])->count()) {
                $errors[] = "Node [$id]: credential $credentialId does not exist for {$manifest['key']} — use list_credentials.";
            }
            if (! $credentialId && $needsCredential) {
                $matching = $credentials->where('integration', $manifest['key']);
                if ($matching->count() === 1) {
                    $credentialId = $matching->first()->id;
                } else {
                    $warnings[] = "$id ({$manifest['name']}) needs a credential: ".($matching->isEmpty() ? 'the user must add one under Credentials.' : 'several exist — ask which one.');
                }
            }

            $ops[$id] = [$manifest, $operation, $isTrigger];
            $graphNodes[] = [
                'id' => $id,
                'type' => $isTrigger ? 'trigger' : (empty($operation['outputs']) ? 'action' : 'branch'),
                'position' => $previous[$id]['position'] ?? null,
                'data' => array_filter([
                    'integration' => $manifest['key'],
                    $isTrigger ? 'trigger' : 'action' => $operation['key'],
                    'label' => $node['label'] ?? $operation['name'],
                    'config' => (array) $config,
                    'credential_id' => $credentialId,
                ], fn ($v) => $v !== null),
            ];
        }

        if (! collect($graphNodes)->contains('id', Workflow::TRIGGER_NODE_ID)) {
            $errors[] = 'Exactly one node must have id "trigger" (e.g. app "manual" op "manual", app "webhook" op "catch", app "schedule" op "cron").';
        }
        $graphEdges = array_map(fn ($e) => [
            'id' => "e-{$e['from']}-{$e['to']}-".($e['branch'] ?? 'out'),
            'source' => (string) ($e['from'] ?? ''),
            'target' => (string) ($e['to'] ?? ''),
            'sourceHandle' => isset($e['branch']) && $e['branch'] !== '' ? (string) $e['branch'] : null,
        ], $edges);

        $this->checkReferences($graphNodes, $graphEdges, $ops, $existing, $errors, $warnings);
        if ($errors) {
            throw new InvalidArgumentException(implode("\n", $errors));
        }

        return [['nodes' => $this->layout($graphNodes, $graphEdges), 'edges' => $graphEdges], array_values(array_unique($warnings))];
    }

    /**
     * Every {{ steps.X.field }} must point at a step that runs earlier on the same path, and at a field
     * that step actually returns. Closed outputs (built-in nodes, triggers) are hard errors; for API
     * responses we only know the convenience fields and past runs, so a mismatch becomes a "Fix:" warning.
     */
    private function checkReferences(array $nodes, array $edges, array $ops, ?Workflow $existing, array &$errors, array &$warnings): void
    {
        $samples = [];
        foreach ($existing?->executions()->latest('id')->limit(5)->get() ?? [] as $run) {
            foreach ($run->steps ?? [] as $step) {
                if (($step['status'] ?? null) === 'success' && is_array($step['output'] ?? null) && ! array_is_list($step['output'])) {
                    $samples[$step['node_id']] ??= ['op' => ($step['integration'] ?? '').'.'.($step['operation'] ?? ''), 'keys' => array_keys($step['output'])];
                }
            }
        }

        foreach ($nodes as $node) {
            $strings = [];
            $config = $node['data']['config'] ?? [];
            array_walk_recursive($config, function ($value) use (&$strings) {
                if (is_string($value)) {
                    $strings[] = $value;
                }
            });
            preg_match_all('/\{\{\{?\s*steps\.([A-Za-z0-9_\-]+)(?:\.([A-Za-z_][A-Za-z0-9_\-]*))?/', implode("\n", $strings), $matches, PREG_SET_ORDER);
            $upstream = GraphRunner::ancestorsOf($node['id'], $edges);

            foreach ($matches as $m) {
                [$ref, $field] = [$m[1], $m[2] ?? ''];
                $at = "Node [{$node['id']}] {{ steps.$ref".($field !== '' ? ".$field" : '').' }}';
                if (! isset($ops[$ref])) {
                    $errors[] = "$at: there is no step \"$ref\". Steps: ".implode(', ', array_keys($ops)).'.';
                } elseif ($ref === $node['id'] || ! in_array($ref, $upstream, true)) {
                    $errors[] = "$at: \"$ref\" does not run before {$node['id']} — connect it upstream or use an earlier step.";
                } elseif ($field !== '') {
                    [$manifest, $operation, $isTrigger] = $ops[$ref];
                    $sample = $samples[$ref] ?? null;
                    $sampleKeys = $sample && $sample['op'] === "{$manifest['key']}.{$operation['key']}" ? $sample['keys'] : [];
                    $closed = $isTrigger ? ['data', 'headers', 'query', 'method'] : ($operation['output'] ?? null);
                    $known = $closed ?? array_values(array_unique([...array_keys($operation['output_fields'] ?? []), ...$sampleKeys]));

                    if ($known !== [] && ! in_array($field, $known, true)) {
                        $what = "{$manifest['key']}.{$operation['key']}";
                        if ($closed !== null) {
                            $errors[] = "$at: $what returns only ".implode(', ', $closed).($isTrigger ? ' (payload fields are under data, e.g. steps.trigger.data.email)' : '').'.';
                        } else {
                            $warnings[] = "Fix: $at — \"$field\" is not a known output of $what. ".($sampleKeys ? 'Its last run returned: ' : 'Documented fields: ').implode(', ', $known).'. Use one of these.';
                        }
                    }
                }
            }
        }
    }

    /** The fields an action returns, for get_app: "text,length", or "text +raw" when the raw API response is passed through too. */
    private function describeOutput(array $op): ?string
    {
        if (isset($op['output'])) {
            return implode(',', $op['output']);
        }
        $fields = array_keys($op['output_fields'] ?? []);

        return $fields ? implode(',', $fields).' +raw API response' : null;
    }

    /** Left-to-right layering for nodes that don't already have a position. */
    private function layout(array $nodes, array $edges): array
    {
        $depth = [Workflow::TRIGGER_NODE_ID => 0];
        for ($pass = 0; $pass < count($nodes); $pass++) {
            foreach ($edges as $edge) {
                if (isset($depth[$edge['source']])) {
                    $depth[$edge['target']] = max($depth[$edge['target']] ?? 0, $depth[$edge['source']] + 1);
                }
            }
        }

        $rows = [];
        foreach ($nodes as &$node) {
            if ($node['position'] === null) {
                $layer = $depth[$node['id']] ?? 1;
                $row = $rows[$layer] = ($rows[$layer] ?? -1) + 1;
                $node['position'] = ['x' => $layer * 260, 'y' => 200 + ($row % 2 ? 1 : -1) * (int) ceil($row / 2) * 170];
            }
        }

        return $nodes;
    }

    private function setActive(int $id, bool $active): array
    {
        $workflow = Workflow::query()->findOrFail($id);
        $workflow->is_active = $active;
        if ($active) {
            GraphValidator::assertActivatable($workflow);
        }
        $workflow->save();

        return [['id' => $id, 'active' => $active], ['type' => $active ? 'activated' : 'deactivated', 'workflow_id' => $id, 'name' => $workflow->name], false];
    }

    private function requestDelete(int $id): array
    {
        $workflow = Workflow::query()->findOrFail($id);

        return [
            ['status' => 'waiting_for_user_confirmation', 'note' => 'Tell the user to click "Delete" on the confirmation card.'],
            ['type' => 'delete_pending', 'workflow_id' => $id, 'name' => $workflow->name],
            false,
        ];
    }

    private function listTemplates(string $query): array
    {
        $words = array_filter(preg_split('/\s+/', Str::lower($query)));

        return collect(app(TemplateLibrary::class)->all())
            ->filter(function ($t) use ($words) {
                $text = Str::lower($t['name'].' '.$t['description'].' '.implode(' ', array_column($t['apps'], 'name')));

                return $words === [] || collect($words)->contains(fn ($w) => str_contains($text, $w));
            })
            ->take(20)
            ->map(fn ($t) => ['key' => $t['key'], 'name' => $t['name'], 'apps' => implode(',', array_column($t['apps'], 'key'))])
            ->values()->all();
    }

    private function useTemplate(string $key, ?string $name, AiConversation $conversation): array
    {
        $template = app(TemplateLibrary::class)->find($key) ?? throw new InvalidArgumentException("No template [$key]. Use list_templates.");

        // Re-run the compact builder so credentials get attached and empty inputs are reported.
        $nodes = array_map(fn ($n) => array_filter([
            'id' => $n['id'], 'app' => $n['data']['integration'], 'op' => $n['data']['trigger'] ?? $n['data']['action'],
            'label' => $n['data']['label'] ?? null, 'config' => $n['data']['config'] ?? [],
        ], fn ($v) => $v !== null), $template['graph']['nodes']);
        $edges = array_map(fn ($e) => array_filter(['from' => $e['source'], 'to' => $e['target'], 'branch' => $e['sourceHandle'] ?? null]), $template['graph']['edges']);

        return $this->saveWorkflow(['name' => $name ?: $template['name'], 'description' => $template['description'], 'nodes' => $nodes, 'edges' => $edges], $conversation);
    }

    private function listCredentials(?string $app): array
    {
        return Credential::query()->when($app, fn ($q) => $q->where('integration', $app))->orderBy('name')
            ->get(['id', 'name', 'integration', 'auth_type'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'app' => $c->integration, 'auth' => $c->auth_type])->all();
    }

    private function testWorkflow(int $id, ?string $node): array
    {
        $workflow = Workflow::query()->findOrFail($id);
        @set_time_limit(300);

        $payload = $workflow->triggerConfig()['sample_payload'] ?? null;
        $data = is_string($payload) && trim($payload) !== '' ? (json_decode($payload, true) ?? []) : ($workflow->trigger_state['sample']['data'] ?? []);
        $only = $node ? GraphRunner::ancestorsOf($node, $workflow->graph['edges'] ?? []) : null;
        $execution = $workflow->start('test', ['data' => $data, 'headers' => [], 'query' => [], 'method' => 'POST'], sync: true, only: $only);

        return [$this->summarize($execution), ['type' => 'tested', 'workflow_id' => $id, 'execution_id' => $execution->id, 'status' => $execution->status], false];
    }

    private function summarize(WorkflowExecution $execution): array
    {
        return array_filter([
            'execution' => $execution->id,
            'status' => $execution->status,
            'ms' => $execution->duration_ms,
            'error' => $execution->error,
            'steps' => array_map(fn ($s) => array_filter([
                'id' => $s['node_id'],
                'status' => $s['status'],
                'http' => $s['http_status'] ?? null,
                'branch' => $s['handle'] ?? null,
                'error' => isset($s['error']) ? Str::limit((string) $s['error'], 300) : null,
                'keys' => is_array($s['output'] ?? null) && ! array_is_list($s['output']) ? implode(',', array_slice(array_keys($s['output']), 0, 25)) : null,
                'out' => isset($s['output']) && $s['status'] === 'success' ? Str::limit(json_encode($s['output'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 240) : null,
            ], fn ($v) => $v !== null), $execution->steps ?? []),
        ], fn ($v) => $v !== null && $v !== []);
    }
}
