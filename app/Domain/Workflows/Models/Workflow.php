<?php

namespace App\Domain\Workflows\Models;

use App\Domain\Engine\Jobs\RunWorkflow;
use App\Domain\Integrations\ManifestLoader;
use App\Domain\License\License;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Throwable;

/**
 * graph = { nodes: [{id, type: trigger|action|branch, position, data: {integration, action|trigger, config, credential_id, label}}],
 *           edges: [{id, source, target, sourceHandle}] }
 */
class Workflow extends Model
{
    public const TRIGGER_NODE_ID = 'trigger';

    protected $fillable = ['name', 'description', 'is_active', 'graph'];

    protected $attributes = [
        'graph' => '{"nodes":[],"edges":[]}',
    ];

    protected function casts(): array
    {
        return [
            'graph' => 'array',
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

    public function triggerNode(): ?array
    {
        return collect($this->graph['nodes'] ?? [])->firstWhere('type', 'trigger');
    }

    /** The trigger's settings, with the manifest defaults the editor shows for anything never edited. */
    public function triggerConfig(): array
    {
        $defaults = collect($this->triggerSpec()['inputs'] ?? [])
            ->filter(fn ($input) => array_key_exists('default', $input))
            ->mapWithKeys(fn ($input) => [$input['key'] => $input['default']])
            ->all();

        return array_merge($defaults, array_filter($this->triggerNode()['data']['config'] ?? [], fn ($value) => $value !== null));
    }

    /** The manifest trigger definition the trigger node points at. */
    public function triggerSpec(): ?array
    {
        $data = $this->triggerNode()['data'] ?? [];

        return app(ManifestLoader::class)->trigger($data['integration'] ?? '', $data['trigger'] ?? '');
    }

    public function webhookUrl(): string
    {
        return url('/api/webhook/'.$this->webhook_token);
    }

    public function mergeTriggerState(array $patch): void
    {
        static::withoutTimestamps(fn () => $this->forceFill([
            'trigger_state' => array_merge($this->trigger_state ?? [], $patch),
        ])->saveQuietly());
    }

    /**
     * Start every active "Error Trigger" workflow with details of a failed run. Error workflows
     * don't trigger each other, so a broken alert workflow can't loop.
     */
    public function startErrorWorkflows(WorkflowExecution $failed): void
    {
        if ($this->trigger_type === 'error' || $failed->mode === 'test') {
            return;
        }

        $step = collect($failed->steps ?? [])->firstWhere('status', 'failed');
        $payload = ['data' => [
            'workflow' => ['id' => $this->id, 'name' => $this->name],
            'execution' => ['id' => $failed->id, 'mode' => $failed->mode, 'error' => $failed->error, 'url' => url('/executions?open='.$failed->id)],
            'failed_step' => $step ? array_intersect_key($step, array_flip(['node_id', 'label', 'error', 'http_status'])) : null,
        ]];

        static::query()->active()->where('trigger_type', 'error')->whereKeyNot($this->id)
            ->each(fn (Workflow $handler) => $handler->start('error', $payload));
    }

    /**
     * Record a run and hand it to the database queue (or run it inline for editor test runs).
     *
     * @param  array  $triggerOutput  becomes {{ steps.trigger.* }}
     * @param  list<string>|null  $only  limit a test run to these node ids
     */
    public function start(string $mode, array $triggerOutput, bool $sync = false, ?array $only = null): WorkflowExecution
    {
        if ($problem = app(License::class)->problem()) {
            throw new \RuntimeException($problem); // no runs (editor, webhooks, schedules) without a license
        }
        $execution = $this->executions()->create([
            'status' => WorkflowExecution::PENDING,
            'mode' => $mode,
            'trigger_payload' => $triggerOutput,
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
