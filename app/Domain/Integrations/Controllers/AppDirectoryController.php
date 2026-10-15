<?php

namespace App\Domain\Integrations\Controllers;

use App\Domain\Integrations\CustomApps;
use App\Domain\Integrations\ManifestLoader;
use App\Domain\Integrations\Models\Credential;
use App\Domain\Workflows\TemplateLibrary;
use App\Http\Controllers\Controller;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Browsable directory of every installed integration.
 */
class AppDirectoryController extends Controller
{
    public function __construct(private ManifestLoader $manifests) {}

    public function index(): Response
    {
        return Inertia::render('Apps/Index', [
            'apps' => $this->manifests->catalog(),
            'connected' => Credential::query()->distinct()->pluck('integration'),
        ]);
    }

    public function show(string $key, TemplateLibrary $templates, CustomApps $custom): Response
    {
        $manifest = $this->manifests->get($key) ?? abort(404);
        $isCustom = ! empty($manifest['custom']);

        return Inertia::render('Apps/Show', [
            'app' => [
                ...Arr::only($manifest, ['key', 'name', 'icon', 'category', 'description', 'website', 'docs_url', 'base_url', 'popular', 'openapi_url']),
                'auth' => array_values(array_diff(array_column($manifest['auth'], 'type'), ['none'])),
                'actions' => array_map(fn ($a) => Arr::only($a, ['key', 'name', 'description']), $manifest['actions']),
                'triggers' => array_map(fn ($t) => Arr::only($t, ['key', 'name', 'description', 'type']), $manifest['triggers']),
                'custom' => $isCustom,
                'used_by' => $isCustom ? $custom->usedBy($key) : [],
            ],
            'credentials' => Credential::query()->where('integration', $key)->orderBy('name')->get(['id', 'name', 'auth_type']),
            'templates' => collect($templates->all())
                ->filter(fn ($t) => collect($t['apps'])->contains('key', $key))
                ->map(fn ($t) => Arr::except($t, ['graph']))
                ->values(),
        ]);
    }
}
