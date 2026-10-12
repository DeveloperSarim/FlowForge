<?php

namespace App\Domain\Workflows\Controllers;

use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\TemplateLibrary;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

class TemplateController extends Controller
{
    public function __construct(private TemplateLibrary $templates) {}

    public function index(): Response
    {
        return Inertia::render('Templates/Index', [
            'templates' => array_map(fn ($t) => Arr::except($t, ['graph']), $this->templates->all()),
        ]);
    }

    public function use(string $key): RedirectResponse
    {
        $template = $this->templates->find($key) ?? abort(404);

        $workflow = Workflow::query()->create([
            'name' => $template['name'],
            'description' => $template['description'] ?? null,
            'graph' => $template['graph'],
        ]);

        return redirect("/workflows/{$workflow->id}")->with('success', 'Template added. Connect credentials on the highlighted steps, then test it.');
    }
}
