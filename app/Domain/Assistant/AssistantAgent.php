<?php

namespace App\Domain\Assistant;

use App\Domain\Assistant\Models\AiConversation;
use App\Domain\Integrations\Handlers\LogicHandler;
use App\Domain\Integrations\ManifestLoader;
use App\Domain\System\SystemSettings;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The FlowForge assistant: turns a user's request into tool calls (search apps, save workflows,
 * test them…) until the job is done, then replies. One user message = one bounded agent loop.
 */
class AssistantAgent
{
    private const MAX_STEPS = 10;

    /** Provider history is restarted (with a recap) beyond this size, to keep input tokens bounded. */
    private const MAX_HISTORY_CHARS = 160000;

    public function __construct(private LlmClient $llm, private WorkflowTools $tools, private SystemSettings $settings) {}

    public function config(): array
    {
        $config = (array) $this->settings->get('ai', []);
        if (empty($config['provider']) || empty($config['api_key'])) {
            throw new RuntimeException('The AI assistant is not set up yet. Add a provider and API key under Settings → AI assistant.');
        }

        return $config;
    }

    /**
     * @return array the assistant's display entry (text, actions, usage)
     */
    /**
     * @param  (callable(array): void)|null  $progress  receives {type: step|text, ...} events while the agent works
     */
    public function reply(AiConversation $conversation, string $message, ?callable $progress = null): array
    {
        $progress ??= fn () => null;
        $started = microtime(true);
        $config = $this->config();
        @set_time_limit(600);

        // Switching provider/model means a fresh provider history (formats differ); the chat UI keeps everything.
        $history = ($conversation->provider === $config['provider'] && $conversation->model === $config['model']) ? ($conversation->history ?? []) : [];
        if (strlen(json_encode($history)) > self::MAX_HISTORY_CHARS) {
            $history = [];
        }
        if ($history === []) {
            $message = $this->recap($conversation).$message;
        }

        $this->llm->addUser($config, $history, $message);

        $text = '';
        $actions = [];
        $usage = ['input' => 0, 'cached' => 0, 'output' => 0, 'calls' => 0];
        $steps = [];
        $fixing = false;

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $progress(['type' => 'thinking', 'label' => $step === 0 ? 'Understanding your request' : 'Deciding the next step']);
            $turn = $this->llm->turn($config, $this->systemPrompt(), $this->tools->definitions(), $history);
            foreach (['input', 'cached', 'output'] as $k) {
                $usage[$k] += $turn->usage[$k];
            }
            $usage['calls']++;

            if (trim($turn->text) !== '') {
                $text = trim($turn->text); // the last narration is the answer
            }
            if ($turn->toolCalls === []) {
                break;
            }
            if (trim($turn->text) !== '') {
                $progress(['type' => 'text', 'text' => Str::limit(trim($turn->text), 300)]);
            }

            $results = [];
            foreach ($turn->toolCalls as $call) {
                $label = ($fixing && in_array($call['name'], ['save_workflow', 'test_workflow'], true) ? 'Fixing: ' : '').$this->describe($call['name'], $call['input']);
                $progress(['type' => 'step', 'index' => count($steps), 'label' => $label, 'state' => 'running']);
                [$output, $action, $isError] = $this->tools->call($call['name'], $call['input'], $conversation);
                $needsFix = $isError || (is_array($output) && (str_contains(json_encode($output['warnings'] ?? []), 'Fix:')
                    || ($call['name'] === 'test_workflow' && ($output['status'] ?? null) === 'failed')));
                $fixing = $needsFix;
                $steps[] = array_filter(['label' => $label, 'state' => $isError ? 'error' : ($needsFix ? 'warn' : 'done'),
                    'detail' => $isError ? Str::limit((string) ($output['error'] ?? ''), 160) : ($needsFix ? 'Found a problem, fixing it' : null)]);
                $progress(['type' => 'step', 'index' => count($steps) - 1] + end($steps));
                $results[] = [
                    'id' => $call['id'],
                    'name' => $call['name'],
                    'content' => Str::limit(json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 6000),
                    'is_error' => $isError,
                ];
                if ($action) {
                    $actions[] = $action;
                }
            }
            $this->llm->addToolResults($config, $history, $results);

            if ($step === self::MAX_STEPS - 1) {
                $text = trim($text."\n\n(I stopped after ".self::MAX_STEPS.' steps. Say "continue" if there is more to do.)');
            }
        }

        $entry = ['role' => 'assistant', 'text' => $text !== '' ? $text : 'Done.', 'actions' => $actions, 'steps' => $steps,
            'ms' => (int) ((microtime(true) - $started) * 1000), 'usage' => $usage, 'at' => now()->toIso8601String()];
        $totals = $conversation->usage ?? ['input' => 0, 'cached' => 0, 'output' => 0, 'calls' => 0];
        foreach ($usage as $k => $v) {
            $totals[$k] = ($totals[$k] ?? 0) + $v;
        }

        $conversation->fill([
            'provider' => $config['provider'],
            'model' => $config['model'],
            'history' => $history,
            'display' => [...($conversation->display ?? []), ['role' => 'user', 'text' => $this->stripRecap($message), 'at' => now()->toIso8601String()], $entry],
            'usage' => $totals,
            'title' => $conversation->title === 'New chat' ? Str::limit($this->stripRecap($message), 60) : $conversation->title,
        ])->save();

        return $entry;
    }

    /** When a provider history starts fresh mid-chat, give the model a compact memory of what happened. */
    private function recap(AiConversation $conversation): string
    {
        $context = [];
        if ($conversation->workflow_id) {
            $context[] = "The user is working on workflow #{$conversation->workflow_id}".($conversation->workflow ? " \"{$conversation->workflow->name}\"" : '').'. Read it with get_workflow before changing it.';
        }
        $done = collect($conversation->display ?? [])->flatMap(fn ($m) => $m['actions'] ?? [])
            ->map(fn ($a) => "{$a['type']} workflow #{$a['workflow_id']}".(isset($a['name']) ? " \"{$a['name']}\"" : ''))->take(-10);
        if ($done->isNotEmpty()) {
            $context[] = 'Earlier in this chat you: '.$done->implode('; ').'.';
        }

        return $context ? '[Context] '.implode(' ', $context)."\n\n" : '';
    }

    private function stripRecap(string $message): string
    {
        return preg_replace('/^\[Context\][^\n]*\n\n/', '', $message);
    }

    /** Static (cache-friendly) system prompt: everything the model must know about FlowForge. */
    /** Plain-language progress label for one tool call. */
    private function describe(string $tool, array $input): string
    {
        $id = (int) ($input['id'] ?? 0);
        $name = Str::limit((string) ($input['name'] ?? ''), 50);

        return match ($tool) {
            'search_apps' => 'Searching apps for "'.Str::limit((string) ($input['query'] ?? ''), 40).'"',
            'get_app' => 'Reading '.(app(ManifestLoader::class)->get((string) ($input['app'] ?? ''))['name'] ?? $input['app'] ?? 'app').' actions',
            'list_workflows' => 'Looking through your workflows',
            'get_workflow' => "Opening workflow #$id",
            'save_workflow' => isset($input['id']) ? "Updating \"$name\"" : "Building \"$name\"",
            'set_workflow_active' => ! empty($input['active']) ? "Switching on workflow #$id" : "Switching off workflow #$id",
            'delete_workflow' => "Asking before deleting workflow #$id",
            'list_templates' => 'Checking ready-made templates',
            'use_template' => 'Creating it from a template',
            'list_credentials' => 'Checking your connected accounts',
            'test_workflow' => "Test-running workflow #$id",
            'get_execution' => "Reading run #$id",
            default => $tool,
        };
    }

    public function systemPrompt(): string
    {
        $guide = self::workflowGuide();

        return <<<PROMPT
You are the FlowForge assistant. FlowForge is a self-hosted workflow automation tool (like n8n/Zapier) with 1000+ app integrations. You build, edit, explain, test and fix workflows by calling tools. Be brief: short sentences, bullet points, no filler. Reply in the user's language (English, Urdu or Roman Urdu).

{$guide}
7. delete_workflow only creates a confirmation card; say so.
8. Finish with a 1-3 line summary: what you built/changed, link like /workflows/ID, and anything the user still has to do. Activate a workflow only if the user asked.
PROMPT;
    }

    /** How FlowForge workflows work and how to build them with the tools: shared by the in-app assistant and MCP clients. */
    public static function workflowGuide(): string
    {
        $operators = implode(', ', LogicHandler::OPERATORS);
        $timezone = app(SystemSettings::class)->timezone();

        return <<<GUIDE
WORKFLOW MODEL
- A workflow is a DAG. Exactly one node has id "trigger"; other nodes are step_1, step_2… Edges go from→to.
- Built-in triggers: manual.manual (run from editor; config sample_payload = JSON string), webhook.catch (config method ANY|GET|POST…, sample_payload), schedule.cron (config cron, e.g. "0 9 * * 1-5"; evaluated in {$timezone}, so write times as the user means them there — never convert), error_trigger.workflow_failed (runs when another workflow fails), voice.call_ended (a voice agent's call finished: data.call.summary, transcript, from, to, outcome, duration_seconds, data.<collected detail>, recording_url; config agent_id optional) and voice.booking_created (a caller booked: data.booking.name, phone, email, service, when, starts_at, ends_at, notes). Apps also have their own polling/webhook triggers.
- Built-in actions: http.request (method,url,query,headers,body,body_format), smtp.send (to,subject,body,format), mysql.query (query,bindings), logic.if (If/Else: two outputs "true"/"false" — edges from it MUST set branch), logic.filter (stops the path if conditions fail), transform.json_parse (source), transform.text_format (text,operation), voice.place_call (agent_id from list_voice_agents, to in +E.164 format, variables keyvalue such as customer_name).
- Every app with an API also has action api_call (method,path,query,headers,body) for endpoints without a dedicated action.
- Errors: a node may set retries (1-5, retried only on temporary errors such as timeouts, 429 or 5xx), on_error "continue" (later steps still run; they can check {{ steps.<id>.error }}) or disabled (skipped). Runs that fail on a temporary error are retried automatically from the failed step unless the user turned that off.
- No app for a service in search_apps (try 2 or 3 searches)? Don't give up and don't fake it with another app: build it with create_custom_app from the service's real, documented API (or its openapi_url), with output_fields for what later steps read, then use it like any app. Custom apps appear under Apps → Custom apps; the user adds a credential for them. One-off calls can use http.request instead.
- Voice agents (AI phone agents that answer or make calls and book appointments) are not workflows: build one with create_voice_agent (list_voice_agents shows existing agents and templates). Connect them to workflows with voice.place_call and the voice.call_ended / voice.booking_created triggers, e.g. send each booking to Google Calendar.

DATA BETWEEN STEPS
- Reference earlier outputs with {{ steps.<node id>.<path> }}, e.g. {{ steps.trigger.data.email }}, {{ steps.step_2.items[0].id }}. Webhook/manual/polling trigger data lives under steps.trigger.data. Also {{ now }}, {{ uuid }} (new unique id each use), {{ workflow.name }}, {{ execution.id }}.
- A value that is exactly one {{ }} keeps its type (arrays/numbers); mixed text becomes a string.
- Only reference fields a step really returns. get_app lists them per action as "out" (e.g. AI chat/completion actions return the reply as text → {{ steps.step_1.text }}; transform.text_format → text,length; logic.if → result). "+raw API response" means the API's JSON is there too. Never guess names like content/message/output; if "out" is missing, use fields seen in test/get_execution results ("keys").
- AI text arrives with real line breaks; don't add steps to "unescape" \\n. The JSON view in the editor shows line breaks as \\n — that is display only.
- Inputs whose help says "leave empty" (e.g. LinkedIn "Post as") default to the connected account: leave them empty.

CONFIG VALUE FORMATS (by input type)
- string/text/password/code/select: string. number: number. boolean: true/false. json: a JSON string.
- list: array of strings. keyvalue: array of {"key","value"}.
- conditions (logic.if / logic.filter): array of {"left","operator","right"}; operators: {$operators}. Plus "combinator": "all" or "any".

HOW TO WORK
1. If a ready-made template fits (list_templates), use_template and adapt it. Otherwise find apps with search_apps (never guess app/action keys), then get_app for the exact input keys of the ops you use.
2. For edits, get_workflow first, then save_workflow with id and the COMPLETE node/edge list (unchanged nodes too).
3. Credentials are attached automatically when exactly one exists for an app; otherwise relay the warning. Never ask for or handle secret keys in chat — the user adds them under Credentials.
4. Leave unknown IDs (spreadsheet id, channel, list id) as "" and tell the user what to fill in. Don't invent IDs or emails.
5. Warnings starting with "Fix:" are mistakes in your step references: correct them and call save_workflow again before replying. Other warnings must be relayed. Errors mean the call failed — fix and retry.
6. test_workflow performs real actions. Only run it when the user asks, or when every step is read-only; otherwise tell the user to click "Execute workflow" in the editor.
GUIDE;
    }
}
