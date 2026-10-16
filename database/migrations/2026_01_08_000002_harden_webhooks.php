<?php

use App\Domain\Workflows\WebhookGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Webhook hardening: blank out credentials (Authorization, API keys, signatures, ?token=…) already stored in
 * webhook samples and run history, and remember which voice agents' providers send the events secret.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Safe to run again if a slow host stopped it half way (the redaction below is repeatable too).
        if (! Schema::hasColumn('voice_agents', 'events_secret_sent')) {
            Schema::table('voice_agents', function (Blueprint $table) {
                $table->boolean('events_secret_sent')->default(false);
            });
        }

        DB::table('workflows')->whereNotNull('trigger_state')->select('id', 'trigger_state')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $state = json_decode($row->trigger_state, true);
                if (is_array($state['sample'] ?? null) && ($sample = WebhookGuard::redact($state['sample'])) !== $state['sample']) {
                    DB::table('workflows')->where('id', $row->id)->update(['trigger_state' => json_encode(['sample' => $sample] + $state)]);
                }
            }
        });

        // Webhook runs, their retries and editor test runs (which reuse the sample) all carry "headers".
        DB::table('workflow_executions')->where('trigger_payload', 'like', '%"headers"%')->select('id', 'trigger_payload', 'steps')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $payload = $before = json_decode($row->trigger_payload, true);
                $steps = $stepsBefore = json_decode($row->steps ?? 'null', true);
                foreach (is_array($steps) ? $steps : [] as $i => $step) {
                    if (($step['type'] ?? null) !== 'trigger' || ! is_array($step['output'] ?? null)) {
                        continue;
                    }
                    $steps[$i]['output'] = WebhookGuard::redact($step['output']);
                    // A truncated output is a JSON preview: cut it before the query string and headers.
                    if (is_string($preview = $step['output']['preview'] ?? null) && ($cut = strrpos($preview, ',"query":')) !== false) {
                        $steps[$i]['output']['preview'] = substr($preview, 0, $cut);
                    }
                }
                $payload = is_array($payload) ? WebhookGuard::redact($payload) : $payload;
                if ($payload !== $before || $steps !== $stepsBefore) {
                    DB::table('workflow_executions')->where('id', $row->id)->update(['trigger_payload' => json_encode($payload), 'steps' => $steps === null ? null : json_encode($steps)]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('voice_agents', function (Blueprint $table) {
            $table->dropColumn('events_secret_sent');
        });
    }
};
