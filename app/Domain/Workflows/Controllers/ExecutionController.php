<?php

namespace App\Domain\Workflows\Controllers;

use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowExecution;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ExecutionController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'workflow' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:pending,running,completed,failed'],
        ]);

        $executions = WorkflowExecution::query()
            ->select(['id', 'workflow_id', 'status', 'mode', 'error', 'started_at', 'finished_at', 'duration_ms', 'created_at', 'attempt', 'retry_at'])
            ->with('workflow:id,name')
            ->when($filters['workflow'] ?? null, fn ($query, $id) => $query->where('workflow_id', $id))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (WorkflowExecution $execution) => [
                'id' => $execution->id,
                'workflow' => ['id' => $execution->workflow_id, 'name' => $execution->workflow?->name],
                'status' => $execution->status,
                'mode' => $execution->mode,
                'error' => $execution->error,
                'duration_ms' => $execution->duration_ms,
                'created_at' => $execution->created_at?->toIso8601String(),
                'attempt' => $execution->attempt ?? 1,
                'retry_at' => $execution->retry_at?->toIso8601String(),
            ]);

        return Inertia::render('Executions/Index', [
            'executions' => $executions,
            'filters' => (object) $filters,
            'workflows' => Workflow::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(WorkflowExecution $execution): JsonResponse
    {
        return response()->json(['execution' => $execution->load('workflow:id,name')->toDebugArray()]);
    }

    /** Retry a run: by default from the step that failed (earlier steps aren't repeated), or from the start. */
    public function retry(Request $request, WorkflowExecution $execution): RedirectResponse
    {
        $fromStart = $request->input('from') === 'start' || $execution->status !== WorkflowExecution::FAILED;
        $execution->forceFill(['retry_at' => null])->save(); // a waiting automatic retry would repeat this one
        $fromStart
            ? $execution->workflow->start('retry', $execution->trigger_payload ?? [])
            : $execution->workflow->resume($execution, 'retry');

        return back()->with('success', ($fromStart ? 'Run again from the start' : 'Retrying from the failed step').': it runs on the next scheduler tick, within a minute.');
    }
}
