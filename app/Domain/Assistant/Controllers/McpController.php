<?php

namespace App\Domain\Assistant\Controllers;

use App\Domain\Assistant\AssistantAgent;
use App\Domain\Assistant\Models\AiConversation;
use App\Domain\Assistant\WorkflowTools;
use App\Domain\System\Updater;
use App\Domain\Workflows\Models\Workflow;
use App\Http\Controllers\Controller;
use App\Models\User;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MCP server (Streamable HTTP, JSON responses) at /api/mcp, so Claude Code, Cursor and other agents can
 * build and run workflows. It exposes the same tools as the in-app AI builder and acts as the token's
 * user, so that person's permissions apply. Stateless: no session id, no server-sent stream.
 */
class McpController extends Controller
{
    private const VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    private const READ_ONLY = ['search_apps', 'get_app', 'list_workflows', 'get_workflow', 'list_templates', 'list_credentials', 'get_execution'];

    public function __construct(private WorkflowTools $tools) {}

    public function __invoke(Request $request): Response
    {
        if (! $request->isMethod('post')) {
            return response('', 405)->header('Allow', 'POST'); // no server-initiated stream on shared hosting
        }

        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload)) {
            return response()->json($this->error(null, -32700, 'Parse error: send one JSON-RPC 2.0 message.'));
        }

        $batch = $payload !== [] && array_is_list($payload); // 2025-03-26 clients may batch
        $replies = array_values(array_filter(array_map(fn ($message) => $this->dispatch($message), $batch ? $payload : [$payload])));

        return $replies === [] ? response('', 202) : response()->json($batch ? $replies : $replies[0]);
    }

    private function dispatch(mixed $message): ?array
    {
        if (! is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0' || ! is_string($message['method'] ?? null)) {
            return $this->error(is_array($message) ? ($message['id'] ?? null) : null, -32600, 'Invalid request.');
        }
        $notification = ! array_key_exists('id', $message);
        $params = (array) ($message['params'] ?? []);

        try {
            $result = match ($message['method']) {
                'initialize' => $this->initialize($params),
                'ping' => new \stdClass,
                'tools/list' => ['tools' => $this->toolList()],
                'tools/call' => $this->call((string) ($params['name'] ?? ''), (array) ($params['arguments'] ?? [])),
                default => $notification ? null : throw new DomainException("Method not found: {$message['method']}", -32601),
            };
        } catch (DomainException $e) {
            return $notification ? null : $this->error($message['id'], $e->getCode() ?: -32603, $e->getMessage());
        }

        return $notification ? null : ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => $result];
    }

    private function initialize(array $params): array
    {
        $requested = (string) ($params['protocolVersion'] ?? '');
        /** @var User $user */
        $user = auth()->user();

        return [
            'protocolVersion' => in_array($requested, self::VERSIONS, true) ? $requested : self::VERSIONS[0],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'flowforge', 'title' => 'FlowForge', 'version' => app(Updater::class)->current()],
            'instructions' => self::instructions($user),
        ];
    }

    /** What an agent needs to know to build workflows here. Also published in /llms-full.txt. */
    public static function instructions(?User $user = null): string
    {
        $base = url('/');
        $who = $user ? "You act as {$user->name} ({$user->role}) and have exactly their access; a tool error says when something needs more.\n\n" : '';

        return "FlowForge ({$base}) is a self-hosted workflow automation tool (like n8n/Zapier) with 1,000+ app integrations. Use these tools to build, edit, test and run workflows for the user.\n\n"
            .$who
            .AssistantAgent::workflowGuide()."\n"
            ."7. delete_workflow deletes immediately and permanently over MCP (there is no confirmation card): only call it when the user clearly asked.\n"
            ."8. Credentials (API keys, OAuth logins) can't be created over MCP: tell the user to add them in FlowForge under Credentials, then attach them by id.\n"
            ."9. Give the user full links, e.g. {$base}/workflows/ID, and say what they still have to fill in. Switch workflows on only when asked.\n";
    }

    private function toolList(): array
    {
        return array_map(fn (array $tool) => [
            'name' => $tool['name'],
            'description' => $tool['name'] === 'delete_workflow' ? 'Permanently delete a workflow and its run history.' : $tool['description'],
            'inputSchema' => $tool['parameters'],
            'annotations' => [
                'readOnlyHint' => in_array($tool['name'], self::READ_ONLY, true),
                'destructiveHint' => $tool['name'] === 'delete_workflow',
                'openWorldHint' => $tool['name'] === 'test_workflow', // a test run performs the steps' real actions
            ],
        ], $this->tools->definitions());
    }

    private function call(string $name, array $arguments): array
    {
        if (! in_array($name, array_column($this->tools->definitions(), 'name'), true)) {
            throw new DomainException("Unknown tool: {$name}", -32602);
        }

        if ($name === 'delete_workflow') {
            [$output, $isError] = $this->delete((int) ($arguments['id'] ?? 0));
        } else {
            [$output, , $isError] = $this->tools->call($name, $arguments, new AiConversation);
        }
        if (is_array($output) && is_string($output['url'] ?? null) && str_starts_with($output['url'], '/')) {
            $output['url'] = url($output['url']);
        }

        return ['content' => [['type' => 'text', 'text' => json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]], 'isError' => $isError];
    }

    /** @return array{0: array, 1: bool} */
    private function delete(int $id): array
    {
        if (! auth()->user()->hasPermission('workflows.delete')) {
            return [['error' => 'This account doesn\'t have "'.User::PERMISSIONS['workflows.delete'].'" access. An admin can grant it under Settings → Team.'], true];
        }
        $workflow = Workflow::query()->find($id);
        if (! $workflow) {
            return [['error' => "No workflow #{$id}. Use list_workflows."], true];
        }
        $workflow->delete();

        return [['deleted' => $id, 'name' => $workflow->name], false];
    }

    private function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
