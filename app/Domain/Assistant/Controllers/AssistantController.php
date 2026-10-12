<?php

namespace App\Domain\Assistant\Controllers;

use App\Domain\Assistant\AssistantAgent;
use App\Domain\Assistant\Models\AiConversation;
use App\Domain\Assistant\Providers;
use App\Domain\Integrations\ManifestLoader;
use App\Domain\System\SystemSettings;
use App\Domain\Workflows\Models\Workflow;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AssistantController extends Controller
{
    public function index(SystemSettings $settings, ManifestLoader $manifests): Response
    {
        $catalog = $manifests->catalogMap();
        $conversations = AiConversation::query()->latest('updated_at')->limit(50)->get(['id', 'title', 'workflow_id', 'updated_at']);
        $workflows = Workflow::query()->whereIn('id', $conversations->pluck('workflow_id')->filter())->pluck('name', 'id');

        return Inertia::render('Assistant', [
            'conversations' => $conversations->map(fn ($c) => [...$c->toArray(), 'workflow_name' => $workflows[$c->workflow_id] ?? null]),
            'ai' => self::status($settings, $manifests),
            // Logos for the starter suggestions.
            'apps' => collect(['typeform', 'google_sheets', 'slack', 'deepseek', 'linkedin', 'shopify', 'whatsapp_business'])
                ->filter(fn ($key) => isset($catalog[$key]))
                ->mapWithKeys(fn ($key) => [$key => ['name' => $catalog[$key]['name'], 'icon' => $catalog[$key]['icon']]]),
        ]);
    }

    /** What the UI needs to know about the configured provider (never the key). */
    public static function status(SystemSettings $settings, ManifestLoader $manifests): ?array
    {
        $ai = (array) $settings->get('ai', []);
        if (empty($ai['provider']) || empty($ai['api_key'])) {
            return null;
        }
        $provider = Providers::get($ai['provider']);

        return ['provider' => $provider['name'], 'model' => $ai['model'], 'icon' => $manifests->catalogMap()[$provider['icon_key']]['icon'] ?? 'plug'];
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['workflow_id' => ['nullable', 'integer', 'exists:workflows,id']]);
        $conversation = AiConversation::query()->create([
            'title' => 'New chat', 'provider' => '', 'model' => '', 'workflow_id' => $data['workflow_id'] ?? null, 'display' => [], 'history' => [],
        ]);

        return response()->json(['conversation' => $this->payload($conversation)]);
    }

    public function show(AiConversation $conversation): JsonResponse
    {
        return response()->json(['conversation' => $this->payload($conversation)]);
    }

    public function message(Request $request, AiConversation $conversation, AssistantAgent $agent): JsonResponse|StreamedResponse
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:8000']]);

        // The chat UI asks for NDJSON to show live progress; any other client gets one JSON reply.
        if (str_contains((string) $request->header('Accept'), 'application/x-ndjson')) {
            return response()->stream(function () use ($agent, $conversation, $data) {
                @ini_set('zlib.output_compression', '0');
                ignore_user_abort(true); // finish (and save) the turn even if the tab closes
                while (ob_get_level() > 0 && ! app()->runningUnitTests()) { // tests capture the stream in a buffer
                    ob_end_flush();
                }
                $emit = function (array $event) {
                    echo json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
                    flush();
                };
                $emit(['type' => 'open', 'pad' => str_repeat(' ', 2048)]); // pushes past small proxy buffers

                try {
                    $agent->reply($conversation, $data['message'], $emit);
                    $emit(['type' => 'done', 'conversation' => $this->payload($conversation->fresh())]);
                } catch (Throwable $e) {
                    report($e);
                    $emit(['type' => 'error', 'message' => $e->getMessage()]);
                }
            }, 200, ['Content-Type' => 'application/x-ndjson', 'Cache-Control' => 'no-cache, no-transform', 'X-Accel-Buffering' => 'no']);
        }

        try {
            $agent->reply($conversation, $data['message']);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['conversation' => $this->payload($conversation->fresh())]);
    }

    /** The assistant can only *propose* deletes; the user confirms here. */
    public function confirmDelete(Request $request, AiConversation $conversation): JsonResponse
    {
        $id = (int) $request->validate(['workflow_id' => ['required', 'integer']])['workflow_id'];
        Workflow::query()->whereKey($id)->first()?->delete();

        $conversation->display = array_map(function ($entry) use ($id) {
            $entry['actions'] = array_map(fn ($a) => ($a['type'] ?? null) === 'delete_pending' && (int) $a['workflow_id'] === $id ? [...$a, 'type' => 'deleted'] : $a, $entry['actions'] ?? []);

            return $entry;
        }, $conversation->display ?? []);
        $conversation->save();

        return response()->json(['conversation' => $this->payload($conversation)]);
    }

    public function destroy(AiConversation $conversation): JsonResponse
    {
        $conversation->delete();

        return response()->json(['ok' => true]);
    }

    private function payload(AiConversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'title' => $conversation->title,
            'workflow_id' => $conversation->workflow_id,
            'messages' => $conversation->display ?? [],
            'usage' => $conversation->usage,
        ];
    }
}
