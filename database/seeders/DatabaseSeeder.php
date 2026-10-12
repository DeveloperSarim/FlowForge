<?php

namespace Database\Seeders;

use App\Domain\Workflows\Models\Workflow;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * One inactive example workflow so a fresh install doesn't open on an empty canvas.
     */
    public function run(): void
    {
        if (Workflow::query()->exists()) {
            return;
        }

        Workflow::query()->create([
            'name' => 'Example: route new signups',
            'description' => 'Webhook → If/Else → HTTP request or text formatter. Click "Test workflow" to run it with the sample payload.',
            'graph' => [
                'nodes' => [
                    ['id' => 'trigger', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 160], 'data' => [
                        'integration' => 'webhook', 'trigger' => 'catch', 'label' => 'New signup',
                        'config' => ['method' => 'POST', 'sample_payload' => "{\n  \"name\": \"Ada Lovelace\",\n  \"email\": \"ada@example.com\",\n  \"plan\": \"pro\"\n}"],
                    ]],
                    ['id' => 'step_1', 'type' => 'branch', 'position' => ['x' => 340, 'y' => 160], 'data' => [
                        'integration' => 'logic', 'action' => 'if', 'label' => 'Is it a Pro plan?',
                        'config' => ['combinator' => 'all', 'conditions' => [['left' => '{{ steps.trigger.data.plan }}', 'operator' => 'equals', 'right' => 'pro']]],
                    ]],
                    ['id' => 'step_2', 'type' => 'action', 'position' => ['x' => 700, 'y' => 40], 'data' => [
                        'integration' => 'http', 'action' => 'request', 'label' => 'Notify CRM',
                        'config' => [
                            'method' => 'POST',
                            'url' => 'https://jsonplaceholder.typicode.com/posts',
                            'body' => "{\n  \"title\": \"New Pro signup\",\n  \"body\": \"{{ steps.trigger.data.name }} <{{ steps.trigger.data.email }}>\"\n}",
                        ],
                    ]],
                    ['id' => 'step_3', 'type' => 'action', 'position' => ['x' => 700, 'y' => 300], 'data' => [
                        'integration' => 'transform', 'action' => 'text_format', 'label' => 'Format greeting',
                        'config' => ['text' => 'Welcome aboard, {{ steps.trigger.data.name }}!', 'operation' => 'upper'],
                    ]],
                ],
                'edges' => [
                    ['id' => 'e1', 'source' => 'trigger', 'target' => 'step_1', 'sourceHandle' => null],
                    ['id' => 'e2', 'source' => 'step_1', 'target' => 'step_2', 'sourceHandle' => 'true'],
                    ['id' => 'e3', 'source' => 'step_1', 'target' => 'step_3', 'sourceHandle' => 'false'],
                ],
            ],
        ]);
    }
}
