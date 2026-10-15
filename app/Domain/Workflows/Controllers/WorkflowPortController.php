<?php

namespace App\Domain\Workflows\Controllers;

use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\WorkflowPorter;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/** Download workflows as a file, and create workflows from one. */
class WorkflowPortController extends Controller
{
    public function __construct(private WorkflowPorter $porter) {}

    public function export(Workflow $workflow): Response
    {
        return $this->download($this->porter->export([$workflow]), Str::slug($workflow->name) ?: 'workflow');
    }

    public function exportAll(): Response
    {
        return $this->download($this->porter->export(Workflow::query()->orderBy('name')->get()), 'flowforge-workflows-'.now()->format('Y-m-d'));
    }

    public function import(Request $request): RedirectResponse
    {
        $text = (string) $request->validate(['document' => ['required', 'string', 'max:5000000']])['document'];
        $document = json_decode($text, true);
        if (! is_array($document)) {
            return back()->withErrors(['document' => 'The file isn\'t valid JSON.']);
        }
        if ($outline = WorkflowPorter::n8nOutline($document)) {
            return redirect('/assistant?prompt='.rawurlencode($outline))->with('success', 'This is an n8n workflow. The AI Builder will rebuild it: check the request and press send.');
        }

        try {
            ['workflows' => $workflows, 'notes' => $notes] = $this->porter->import($document);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['document' => $e->getMessage()]);
        }

        $done = count($workflows) === 1 ? "Imported \"{$workflows[0]->name}\". It starts switched off." : 'Imported '.count($workflows).' workflows. They start switched off.';

        return redirect(count($workflows) === 1 ? "/workflows/{$workflows[0]->id}" : '/workflows')->with('success', trim($done.' '.implode(' ', $notes)));
    }

    private function download(array $document, string $name): Response
    {
        return response(json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => "attachment; filename=\"{$name}.flowforge.json\"",
        ]);
    }
}
