<?php

namespace App\Domain\Integrations\Controllers;

use App\Domain\Integrations\CustomApps;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Custom apps from the Apps page: import an OpenAPI document, or remove one. (The AI builds them with create_custom_app.) */
class CustomAppController extends Controller
{
    public function __construct(private CustomApps $apps) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['source' => ['required', 'string', 'max:8000000'], 'name' => ['nullable', 'string', 'max:120']]);
        try {
            ['manifest' => $app] = $this->apps->importOpenApi($data['source'], $data['name'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['source' => $e->getMessage()]);
        }

        return redirect("/apps/{$app['key']}")->with('success', "{$app['name']} was added with ".count($app['actions']).' actions.');
    }

    public function destroy(string $key): RedirectResponse
    {
        try {
            $this->apps->delete($key);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect('/apps')->with('success', 'Custom app deleted.');
    }
}
