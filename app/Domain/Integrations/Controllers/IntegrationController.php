<?php

namespace App\Domain\Integrations\Controllers;

use App\Domain\Integrations\CredentialVault;
use App\Domain\Integrations\ManifestLoader;
use App\Domain\Integrations\Models\Credential;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Full manifest for one integration (the builder palette only gets the lightweight catalog),
 * plus the credentials that can be used with it.
 */
class IntegrationController extends Controller
{
    public function __invoke(string $key, ManifestLoader $manifests, CredentialVault $vault): JsonResponse
    {
        $manifest = $manifests->get($key) ?? abort(404, 'Unknown integration.');

        $manifest['auth'] = array_map(fn (array $spec) => array_merge($spec, ['fields' => $vault->fields($spec)]), $manifest['auth']);
        $manifest['credentials'] = Credential::query()
            ->where('integration', $key)
            ->orderBy('name')
            ->get(['id', 'name', 'auth_type'])
            ->toArray();

        return response()->json(['integration' => $manifest]);
    }
}
