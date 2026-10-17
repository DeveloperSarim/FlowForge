<?php

namespace App\Domain\Workflows\Models;

use App\Domain\Engine\Jobs\RunWorkflow;
use App\Domain\Integrations\ManifestLoader;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * graph = { nodes: [{id, type: trigger|action|branch, position, data: {integration, action|trigger, config, credential_id, label,
 *                     disabled?, retries?, on_error?}}],
 *           edges: [{id, source, target, sourceHandle}] }
 * settings = { auto_retry?: bool (default true), retry_on?: "temporary" (default) | "all" }
 *
 * A workflow can have several triggers, each starting its own flow: the main one has id "trigger", the others
 * "trigger_2", "trigger_3"… A run records which one started it (trigger_node, null for the main one) and only that
 * trigger's flow runs. trigger_type is the main trigger's kind; trigger_types lists every kind (",webhook,schedule,").
 * trigger_state keeps the main trigger's state at the top level and the others' under nodes.<id>.
 */
class Workflow extends Model
{
    public const TRIGGER_NODE_ID = 'trigger';

    /** Minutes before each automatic retry of a run that failed on a temporary error (like Zapier's Autoreplay). */
    public const RETRY_DELAYS = [2, 10, 60, 180, 360];

    /** Runs that are retried automatically; editor tests and manual retries are not (someone is watching). */
    public const RETRYABLE_MODES = ['webhook', 'schedule', 'polling', 'voice', 'error', 'auto_retry'];

    protected $fillable = ['name', 'description', 'is_active', 'graph', 'settings'];

    protected $attributes = [
        'graph' => '{"nodes":[],"edges":[]}',
    ];

    protected function casts(): array
    {
        return [
            'graph' => 'array',
            'settings' => 'array',
            'trigger_state' => 'array',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Workflow $workflow) {
            $workflow->webhook_token ??= Str::random(48);
        });

        static::saving(function (Workflow $workflow) {
            $workflow->trigger_type = $workflow->triggerSpec()['type'] ?? 'manual';
            $types = collect($workflow->triggerNodes())->map(fn ($node) => $workflow->triggerSpec($node['id'])['type'] ?? 'manual')->unique();
            $workflow->trigger_types = ','.$types->implode(',').',';
        });
    }

    public static function defaultGraph(): array
    {
        return [
            'nodes' => [[
                'id' => self::TRIGGER_NODE_ID,
                'type' => 'trigger',
                'position' => ['x' => 80, 'y' => 200],
                'data' => ['integration' => 'manual', 'trigger' => 'manual', 'config' => [], 'label' => 'Manual trigger'],
            ]],
            'edges' => [],
        ];
    }

    public function executions(): HasMany
    {
        return $this->hasMany(WorkflowExecution::class);
    }

    public function latestExecution(): HasOne
    {
        return $this->hasOne(WorkflowExecution::class)->latestOfMany();
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** Workflows with at least one trigger of this kind (schedule, polling, webhook, error, voice_call…). */
    public function scopeWithTrigger(Builder $query, string $type): void
    {
        $query->where(fn (Builder $q) => $q->where('trigger_type', $type)->orWhere('trigger_types', 'like', "%,{$type},%"));
    }

    /** Every trigger node, the main one first. */
    public function triggerNodes(): array
    {
        $nodes = array_values(array_filter($this->graph['nodes'] ?? [], fn ($node) => ($node['type'] ?? null) === 'trigger'));
        usort($nodes, fn ($a, $b) => ($b['id'] === self::TRIGGER_NODE_ID) <=> ($a['id'] === self::TRIGGER_NODE_ID));

        return $nodes;
    }

    /** A trigger node by id; without one, the main trigger. */
    public function triggerNode(?string $id = null): ?array
    {
        return $id === null ? ($this->triggerNodes()[0] ?? null) : collect($this->triggerNodes())->firstWhere('id', $id);
    }

    /** The ids of this workflow's triggers of one kind. */
    public function triggerIds(string $type): array
    {
        return collect($this->triggerNodes())->pluck('id')->filter(fn ($id) => ($this->triggerSpec($id)['type'] ?? null) === $type)->values()->all();
    }

    /** Whether this id is the main trigger (null means the main one). */
    public function isMainTrigger(?string $id): bool
    {
        return $id === null || $id === ($this->triggerNode()['id'] ?? self::TRIGGER_NODE_ID);
    }

    /** A trigger's settings, with the manifest defaults the editor shows for anything never edited. */
    public function triggerConfig(?string $id = null): array
    {
        $defaults = collect($this->triggerSpec($id)['inputs'] ?? [])
            ->filter(fn ($input) => array_key_exists('default', $input))
            ->mapWithKeys(fn ($input) => [$input['key'] => $input['default']])
            ->all();

        return array_merge($defaults, array_filter($this->triggerNode($id)['data']['config'] ?? [], fn ($value) => $value !== null));
    }

    /** The manifest trigger definition a trigger node points at. */
    public function triggerSpec(?string $id = null): ?array
    {
        $data = $this->triggerNode($id)['data'] ?? [];

        return app(ManifestLoader::class)->trigger($data['integration'] ?? '', $data['trigger'] ?? '');
    }

    /** One trigger's state (last poll, seen items, captured sample…). */
    public function triggerState(?string $id = null): array
    {
        return $this->isMainTrigger($id) ? ($this->trigger_state ?? []) : ($this->trigger_state['nodes'][$id] ?? []);
    }

    /** The URL of a webhook trigger: the main one at /api/webhook/{token}, the others at /api/webhook/{token}/{id}. */
    public function webhookUrl(?string $id = null): string
    {
        return url('/api/webhook/'.$this->webhook_token.($this->isMainTrigger($id) ? '' : '/'.$id));
    }

    /**
     * Merge keys into a trigger's state as stored now, not as this (possibly stale) copy last saw it: a poll,
     * a schedule tick and a webhook sample can write at the same time without undoing each other.
     */
    public function mergeTriggerState(array $patch, ?string $trigger = null): void
    {
        $main = $this->isMainTrigger($trigger);
        $state = DB::transaction(function () use ($patch, $main, $trigger) {
            $state = static::query()->lockForUpdate()->whereKey($this->getKey())->value('trigger_state') ?? [];
            if ($main) {
                $state = array_merge($state, $patch);
            } else {
                $state['nodes'][$trigger] = array_merge($state['nodes'][$trigger] ?? [], $patch);
            }
            static::query()->whereKey($this->getKey())->toBase()->update(['trigger_state' => json_encode($state)]);

            return $state;
        });

        $this->forceFill(['trigger_state' => $state])->syncOriginalAttribute('trigger_state');
    }

    /**
     * Start every active "Error Trigger" workflow with details of a failed run. Error workflows
     * don't trigger each other, so a broken alert workflow can't loop.
     */
    public function startErrorWorkflows(WorkflowExecution $failed): void
    {
        if (in_array($failed->mode, ['error', 'test'], true)) {
            return; // a failed alert run doesn't start alerts, so a broken alert workflow can't loop
        }

        $step = collect($failed->steps ?? [])->firstWhere('status', 'failed');
        $payload = ['data' => [
            'workflow' => ['id' => $this->id, 'name' => $this->name],
            'execution' => ['id' => $failed->id, 'mode' => $failed->mode, 'error' => $failed->error, 'url' => url('/executions?open='.$failed->id)],
            'failed_step' => $step ? array_intersect_key($step, array_flip(['node_id', 'label', 'error', 'http_status'])) : null,
        ]];

        static::query()->active()->withTrigger('error')->whereKeyNot($this->id)
            ->each(fn (Workflow $handler) => collect($handler->triggerIds('error'))->each(fn ($id) => $handler->start('error', $payload, trigger: $id)));
    }

    public function autoRetries(): bool
    {
        return (bool) ($this->settings['auto_retry'] ?? true);
    }

    /** Which failed runs are retried automatically: only temporary problems (app down, timeout, rate limit) or every failure. */
    public function retryOn(): string
    {
        return ($this->settings['retry_on'] ?? null) === 'all' ? 'all' : 'temporary';
    }

    /** Run a failed execution again from its failed step; the steps that succeeded are reused, not repeated. */
    public function resume(WorkflowExecution $failed, string $mode, bool $sync = false): WorkflowExecution
    {
        $execution = $this->executions()->create([
            'status' => WorkflowExecution::PENDING,
            'mode' => $mode,
            'trigger_payload' => $failed->trigger_payload ?? [],
            'attempt' => ($failed->attempt ?? 1) + 1,
            'retry_of' => $failed->id,
            'trigger_node' => $failed->trigger_node,
        ]);
        $failed->forceFill(['retry_at' => null])->save(); // a waiting automatic retry is now redundant

        $sync ? RunWorkflow::dispatchSync($execution->id) : RunWorkflow::dispatch($execution->id);

        return $execution->refresh();
    }

    /**
     * Record a run and hand it to the database queue (or run it inline for editor test runs).
     *
     * @param  array  $triggerOutput  becomes {{ steps.<trigger id>.* }}
     * @param  list<string>|null  $only  limit a test run to these node ids
     * @param  string|null  $trigger  the trigger that fired (null: the main one); only its flow runs
     */
    public function start(string $mode, array $triggerOutput, bool $sync = false, ?array $only = null, ?string $trigger = null): WorkflowExecution
    {
        $execution = $this->executions()->create([
            'status' => WorkflowExecution::PENDING,
            'mode' => $mode,
            'trigger_payload' => $triggerOutput,
            'trigger_node' => $this->isMainTrigger($trigger) ? null : $trigger,
        ]);

        if (! $sync) {
            RunWorkflow::dispatch($execution->id);
        } else {
            try {
                RunWorkflow::dispatchSync($execution->id, $only);
            } catch (Throwable $e) {
                (new RunWorkflow($execution->id))->failed($e);
            }
        }

        return $execution->refresh();
    }
}
