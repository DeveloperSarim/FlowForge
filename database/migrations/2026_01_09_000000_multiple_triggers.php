<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Several triggers in one workflow, each starting its own flow: every kind of trigger a workflow has (",webhook,schedule,")
 * so the scheduler and webhooks can find it, and which trigger started each run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('workflows', 'trigger_types')) {
            Schema::table('workflows', function (Blueprint $table) {
                $table->string('trigger_types')->nullable();
            });
        }
        if (! Schema::hasColumn('workflow_executions', 'trigger_node')) {
            Schema::table('workflow_executions', function (Blueprint $table) {
                $table->string('trigger_node', 64)->nullable(); // null: the main trigger
            });
        }

        // Every workflow so far has exactly one trigger.
        DB::table('workflows')->whereNull('trigger_types')->orderBy('id')->chunkById(200, function ($workflows) {
            foreach ($workflows as $workflow) {
                DB::table('workflows')->where('id', $workflow->id)->update(['trigger_types' => ",{$workflow->trigger_type},"]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('workflow_executions', function (Blueprint $table) {
            $table->dropColumn('trigger_node');
        });
        Schema::table('workflows', function (Blueprint $table) {
            $table->dropColumn('trigger_types');
        });
    }
};
