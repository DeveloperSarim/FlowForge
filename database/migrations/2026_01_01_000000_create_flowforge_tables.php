<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credentials', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('integration', 100)->index();
            $table->string('auth_type', 40);
            $table->longText('data'); // encrypted JSON
            $table->timestamps();
        });

        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(false);
            $table->json('graph')->nullable();
            $table->string('trigger_type', 20)->default('manual');
            $table->string('webhook_token', 64)->unique();
            $table->json('trigger_state')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'trigger_type']);
        });

        Schema::create('workflow_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->string('mode', 20);
            $table->json('trigger_payload')->nullable();
            $table->json('steps')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['workflow_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_executions');
        Schema::dropIfExists('workflows');
        Schema::dropIfExists('credentials');
    }
};
