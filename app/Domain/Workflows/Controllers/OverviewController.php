<?php

namespace App\Domain\Workflows\Controllers;

use App\Domain\Integrations\ManifestLoader;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowExecution;
use App\Domain\Workflows\TemplateLibrary;
use App\Http\Controllers\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Home dashboard: 7-day production metrics vs the previous 7 days, a 14-day run chart,
 * recent runs, recently edited workflows and a few templates.
 */
class OverviewController extends Controller
{
    public function __invoke(ManifestLoader $manifests, TemplateLibrary $templates): Response
    {
        $now = now();
        $catalog = $manifests->catalogMap();

        // Editor test runs are excluded from metrics, like n8n's "production executions".
        $production = fn () => WorkflowExecution::query()->where('mode', '!=', 'test');

        $window = function (Carbon $from, Carbon $to) use ($production) {
            $runs = $production()->whereBetween('created_at', [$from, $to]);
            $total = (clone $runs)->count();
            $failed = (clone $runs)->where('status', WorkflowExecution::FAILED)->count();

            return [
                'total' => $total,
                'failed' => $failed,
                'failure_rate' => $total ? round($failed / $total * 100, 1) : 0,
                'avg_ms' => (int) round((clone $runs)->whereNotNull('duration_ms')->avg('duration_ms') ?? 0),
            ];
        };

        $daily = $production()
            ->where('created_at', '>=', $now->copy()->subDays(13)->startOfDay())
            ->selectRaw('DATE(created_at) as day, status, COUNT(*) as runs')
            ->groupBy('day', 'status')
            ->get()
            ->groupBy('day');

        $series = collect(range(13, 0))->map(function ($daysAgo) use ($now, $daily) {
            $day = $now->copy()->subDays($daysAgo)->toDateString();
            $rows = $daily->get($day, collect());

            return [
                'day' => $day,
                'succeeded' => (int) $rows->where('status', WorkflowExecution::COMPLETED)->sum('runs'),
                'failed' => (int) $rows->where('status', WorkflowExecution::FAILED)->sum('runs'),
            ];
        })->values();

        $workflows = Workflow::query()->latest('updated_at')->limit(6)->get();

        return Inertia::render('Overview', [
            'metrics' => [
                'current' => $window($now->copy()->subDays(7), $now),
                'previous' => $window($now->copy()->subDays(14), $now->copy()->subDays(7)),
                'active_workflows' => Workflow::query()->active()->count(),
                'workflows' => Workflow::query()->count(),
                'apps' => count($catalog),
            ],
            'series' => $series,
            'recent' => WorkflowExecution::query()
                ->with('workflow:id,name')
                ->latest('id')->limit(7)
                ->get(['id', 'workflow_id', 'status', 'mode', 'duration_ms', 'created_at'])
                ->map(fn (WorkflowExecution $e) => [
                    'id' => $e->id, 'status' => $e->status, 'mode' => $e->mode, 'duration_ms' => $e->duration_ms,
                    'created_at' => $e->created_at?->toIso8601String(), 'workflow' => $e->workflow?->only('id', 'name'),
                ]),
            'workflows' => $workflows->map(fn (Workflow $w) => [
                'id' => $w->id, 'name' => $w->name, 'is_active' => $w->is_active, 'trigger_type' => $w->trigger_type,
                'updated_at' => $w->updated_at?->toIso8601String(), 'apps' => WorkflowController::appsFor($w, $catalog),
            ]),
            'templates' => array_map(fn ($t) => Arr::except($t, ['graph']), array_slice($templates->all(), 0, 3)),
        ]);
    }
}
