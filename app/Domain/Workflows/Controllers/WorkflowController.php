<?php

namespace App\Domain\Workflows\Controllers;

use App\Domain\Assistant\Controllers\AssistantController;
use App\Domain\Engine\GraphRunner;
use App\Domain\Engine\PollerService;
use App\Domain\Integrations\Handlers\LogicHandler;
use App\Domain\Integrations\ManifestLoader;
use App\Domain\System\SystemSettings;
use App\Domain\Workflows\GraphValidator;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowExecution;
use App\Http\Controllers\Controller;
use Cron\CronExpression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

class WorkflowController extends Controller
{
    public function __construct(private ManifestLoader $manifests) {}

    public function index(): Response
    {
        $workflows = Workflow::query()
            ->with(['latestExecution' => fn ($query) => $query->select(
                'workflow_executions.id', 'workflow_executions.workflow_id', 'workflow_executions.status', 'workflow_executions.created_at'
            )])
            ->withCount(['executions as runs_24h' => fn ($query) => $query->where('created_at', '>=', now()->subDay())])
            ->orderByDesc('updated_at')
            ->get();

        $since = WorkflowExecution::query()->where('created_at', '>=', now()->subDay());

        return Inertia::render('Workflows/Index', [
            'workflows' => $workflows->map(fn (Workflow $workflow) => [
                'id' => $workflow->id,
                'name' => $workflow->name,
                'description' => $workflow->description,
                'is_active' => $workflow->is_active,
                'auto_retry' => $workflow->autoRetries(),
                'trigger_type' => $workflow->trigger_type,
                'updated_at' => $workflow->updated_at?->toIso8601String(),
                'created_at' => $workflow->created_at?->toIso8601String(),
                'runs_24h' => $workflow->runs_24h,
                'last_run' => $workflow->latestExecution ? [
                    'status' => $workflow->latestExecution->status,
                    'created_at' => $workflow->latestExecution->created_at?->toIso8601String(),
                ] : null,
                'apps' => $this->apps($workflow),
            ]),
            'stats' => [
                'workflows' => $workflows->count(),
                'active' => $workflows->where('is_active', true)->count(),
                'runs_24h' => (clone $since)->count(),
                'failed_24h' => (clone $since)->where('status', WorkflowExecution::FAILED)->count(),
                'integrations' => $this->manifests->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'integration' => ['nullable', 'string'],
            'action' => ['nullable', 'string'],
        ]);

        $graph = Workflow::defaultGraph();

        // "Use in a workflow" from the app directory: start with that app's action already on the canvas.
        if (($data['integration'] ?? null) && ($action = $this->manifests->action($data['integration'], $data['action'] ?? ''))) {
            $graph['nodes'][] = [
                'id' => 'step_1',
                'type' => empty($action['outputs']) ? 'action' : 'branch',
                'position' => ['x' => 380, 'y' => 200],
                'data' => ['integration' => $data['integration'], 'action' => $action['key'], 'config' => [], 'label' => $action['name']],
            ];
            $graph['edges'][] = ['id' => 'e-trigger-step_1', 'source' => 'trigger', 'target' => 'step_1', 'sourceHandle' => null];
        }

        $workflow = Workflow::query()->create(Arr::only($data, ['name', 'description']) + ['graph' => $graph]);

        return redirect("/workflows/{$workflow->id}");
    }

    public function show(Workflow $workflow): Response
    {
        return Inertia::render('Workflows/Builder', [
            'workflow' => $this->builderPayload($workflow),
            'catalog' => $this->manifests->catalog(),
            'operators' => LogicHandler::OPERATORS,
            'lastExecution' => $workflow->executions()->latest('id')->first()?->toDebugArray(),
            'ai' => AssistantController::status(app(SystemSettings::class), $this->manifests),
        ]);
    }

    public function update(Request $request, Workflow $workflow): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'array'],
            'settings.auto_retry' => ['sometimes', 'boolean'],
            'settings.retry_on' => ['sometimes', 'in:temporary,all'],
            'graph' => ['sometimes', 'array'],
            'graph.nodes' => ['required_with:graph', 'array', 'max:500'],
            'graph.nodes.*.id' => ['required', 'string', 'max:64'],
            'graph.nodes.*.type' => ['required', 'in:trigger,action,branch'],
            'graph.nodes.*.position.x' => ['required', 'numeric'],
            'graph.nodes.*.position.y' => ['required', 'numeric'],
            'graph.nodes.*.data' => ['nullable', 'array'],
            'graph.edges' => ['present_with:graph', 'array'],
            'graph.edges.*.source' => ['required', 'string'],
            'graph.edges.*.target' => ['required', 'string'],
            'graph.edges.*.sourceHandle' => ['nullable', 'string'],
        ]);

        if (isset($data['graph'])) {
            $data['graph'] = GraphValidator::normalize($data['graph']);
        }
        if (isset($data['settings'])) {
            $data['settings'] = array_merge($workflow->settings ?? [], array_intersect_key($data['settings'], ['auto_retry' => true, 'retry_on' => true]));
        }

        $workflow->fill($data);

        if ($workflow->is_active) {
            GraphValidator::assertActivatable($workflow);
        }

        $workflow->save();

        return response()->json(['workflow' => $this->builderPayload($workflow)]);
    }

    /** Next run times for a cron expression in the system timezone (schedule trigger preview). */
    public function schedulePreview(Request $request, SystemSettings $settings): JsonResponse
    {
        $cron = trim((string) $request->query('cron'));
        if (! CronExpression::isValidExpression($cron)) {
            return response()->json(['valid' => false, 'next' => []]);
        }

        $timezone = $settings->timezone();
        $expression = new CronExpression($cron);

        return response()->json(['valid' => true, 'timezone' => $timezone, 'next' => collect(range(0, 2))
            ->map(fn ($i) => $expression->getNextRunDate(now($timezone), $i)->format(DATE_ATOM))]);
    }

    /** A new webhook address for a URL that leaked; the old one stops working at once. */
    public function newWebhookUrl(Workflow $workflow): JsonResponse
    {
        $workflow->forceFill(['webhook_token' => Str::random(48)])->save();

        return response()->json(['webhook_url' => $workflow->webhookUrl()]);
    }

    public function duplicate(Workflow $workflow): RedirectResponse
    {
        $copy = Workflow::query()->create([
            'name' => $workflow->name.' (copy)',
            'description' => $workflow->description,
            'graph' => $workflow->graph,
        ]);

        return redirect("/workflows/{$copy->id}")->with('success', 'Duplicated. The copy starts inactive.');
    }

    /** Recent runs for the editor's Executions tab. */
    public function runs(Workflow $workflow): JsonResponse
    {
        return response()->json(['executions' => $workflow->executions()
            ->latest('id')->limit(50)
            ->get(['id', 'status', 'mode', 'error', 'duration_ms', 'created_at'])
            ->map(fn (WorkflowExecution $e) => [
                'id' => $e->id, 'status' => $e->status, 'mode' => $e->mode, 'error' => $e->error,
                'duration_ms' => $e->duration_ms, 'created_at' => $e->created_at?->toIso8601String(),
            ])]);
    }

    public function destroy(Workflow $workflow): RedirectResponse
    {
        $workflow->delete();

        return redirect('/workflows')->with('success', "Deleted \"{$workflow->name}\".");
    }

    /**
     * Editor "Test workflow": runs synchronously so the debug panel can show results immediately.
     */
    public function test(Request $request, Workflow $workflow, PollerService $poller): JsonResponse
    {
        @set_time_limit(300);

        // "Execute step": run only the chosen node and what feeds into it.
        $node = $request->input('node');
        $only = is_string($node) && $node !== '' ? GraphRunner::ancestorsOf($node, $workflow->graph['edges'] ?? []) : null;

        try {
            $payload = match ($workflow->triggerSpec()['type'] ?? 'manual') {
                'polling' => ['data' => $poller->fetch($workflow)[0] ?? null],
                'schedule' => ['data' => ['timestamp' => now()->toIso8601String(), 'cron' => $workflow->triggerConfig()['cron'] ?? null]],
                default => $this->samplePayload($workflow),
            };
        } catch (Throwable $e) {
            return response()->json(['message' => 'Trigger failed: '.$e->getMessage()], 422);
        }

        $execution = $workflow->start('test', $payload, sync: true, only: $only);

        return response()->json(['execution' => $execution->load('workflow')->toDebugArray()]);
    }

    private function samplePayload(Workflow $workflow): array
    {
        $sample = $workflow->triggerConfig()['sample_payload'] ?? null;

        if (is_string($sample) && trim($sample) !== '') {
            $data = json_decode($sample, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('The test payload is not valid JSON: '.json_last_error_msg());
            }

            return ['data' => $data, 'headers' => [], 'query' => [], 'method' => 'POST'];
        }

        // Last request captured at the webhook URL while the workflow was inactive.
        return $workflow->trigger_state['sample'] ?? ['data' => (object) [], 'headers' => [], 'query' => [], 'method' => 'POST'];
    }

    private function builderPayload(Workflow $workflow): array
    {
        return [
            'id' => $workflow->id,
            'name' => $workflow->name,
            'description' => $workflow->description,
            'is_active' => $workflow->is_active,
            'trigger_type' => $workflow->trigger_type,
            'graph' => $workflow->graph,
            'settings' => ['auto_retry' => $workflow->autoRetries(), 'retry_on' => $workflow->retryOn()],
            'webhook_url' => $workflow->webhookUrl(),
            'trigger_state' => Arr::except($workflow->trigger_state ?? [], ['seen']),
            'updated_at' => $workflow->updated_at?->toIso8601String(),
        ];
    }

    private function apps(Workflow $workflow): array
    {
        return self::appsFor($workflow, $this->manifests->catalogMap());
    }

    /** The distinct apps a workflow uses, for logo stacks. */
    public static function appsFor(Workflow $workflow, array $catalog): array
    {
        return collect($workflow->graph['nodes'] ?? [])
            ->pluck('data.integration')->filter()->unique()->take(6)
            ->map(fn ($key) => ['key' => $key, 'name' => $catalog[$key]['name'] ?? $key, 'icon' => $catalog[$key]['icon'] ?? 'plug'])
            ->values()->all();
    }
}
