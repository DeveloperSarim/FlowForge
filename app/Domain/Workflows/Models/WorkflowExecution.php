<?php

namespace App\Domain\Workflows\Models;

use App\Domain\Integrations\ManifestLoader;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowExecution extends Model
{
    use Prunable;

    public const PENDING = 'pending';

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    protected $fillable = ['status', 'mode', 'trigger_payload', 'steps', 'error', 'started_at', 'finished_at', 'duration_ms'];

    protected function casts(): array
    {
        return [
            'trigger_payload' => 'array',
            'steps' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /** Full run record for the debug drawer. */
    public function toDebugArray(): array
    {
        return [
            'id' => $this->id,
            'workflow' => ['id' => $this->workflow_id, 'name' => $this->workflow?->name],
            'status' => $this->status,
            'mode' => $this->mode,
            'error' => $this->error,
            'trigger_payload' => $this->trigger_payload,
            'steps' => $this->stepsWithIcons(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'duration_ms' => $this->duration_ms,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** Steps annotated with their app logo for the debugger. */
    private function stepsWithIcons(): array
    {
        $catalog = app(ManifestLoader::class)->catalogMap();

        return array_map(fn (array $step) => $step + ['icon' => $catalog[$step['integration'] ?? '']['icon'] ?? 'plug'], $this->steps ?? []);
    }

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(max(1, config('flowforge.execution_retention_days'))));
    }
}
