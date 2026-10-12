<?php

namespace App\Domain\Workflows;

use App\Domain\Integrations\ManifestLoader;
use Illuminate\Support\Facades\File;

/**
 * Ready-made workflows in resources/templates/*.json: {key, name, description, category, graph}.
 * Templates whose apps aren't installed are hidden.
 */
class TemplateLibrary
{
    public function __construct(private ManifestLoader $manifests) {}

    /** @return list<array> templates with the apps they use (for logo rows) */
    public function all(): array
    {
        $catalog = $this->manifests->catalogMap();
        $templates = [];

        foreach (File::glob(resource_path('templates/*.json')) as $file) {
            $template = json_decode(file_get_contents($file), true);
            if (! is_array($template) || empty($template['key']) || empty($template['graph']['nodes'])) {
                continue;
            }

            $keys = collect($template['graph']['nodes'])->pluck('data.integration')->filter()->unique()->values();
            if ($keys->contains(fn ($key) => ! isset($catalog[$key]))) {
                continue;
            }

            $template['apps'] = $keys
                ->reject(fn ($key) => in_array($catalog[$key]['category'], ['Triggers', 'Flow control', 'Data transformation'], true))
                ->map(fn ($key) => ['key' => $key, 'name' => $catalog[$key]['name'], 'icon' => $catalog[$key]['icon']])
                ->values()->all();
            $template['steps'] = count($template['graph']['nodes']);
            $templates[] = $template;
        }

        usort($templates, fn ($a, $b) => [$b['featured'] ?? false, $a['name']] <=> [$a['featured'] ?? false, $b['name']]);

        return $templates;
    }

    public function find(string $key): ?array
    {
        return collect($this->all())->firstWhere('key', $key);
    }
}
