<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_agents', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('provider', 20)->default('browser'); // browser | vapi | elevenlabs
            $table->foreignId('credential_id')->nullable()->constrained()->nullOnDelete();
            $table->json('config');
            $table->string('token', 48)->unique(); // secret part of the tool / event URL the provider calls
            $table->string('remote_id')->nullable(); // the provider's assistant / agent id
            $table->string('remote_tool_id')->nullable();
            $table->string('phone_number_id')->nullable();
            $table->string('phone_label')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->text('sync_error')->nullable();
            $table->timestamp('polled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('voice_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voice_agent_id')->constrained()->cascadeOnDelete();
            $table->string('remote_id')->nullable()->index();
            $table->string('direction', 10); // inbound | outbound | web
            $table->string('status', 20)->default('queued'); // queued | ringing | in-progress | completed | no-answer | failed
            $table->string('from_number', 40)->nullable();
            $table->string('to_number', 40)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->decimal('cost', 10, 4)->nullable();
            $table->text('summary')->nullable();
            $table->string('outcome', 20)->nullable(); // success | failure | unknown
            $table->json('transcript')->nullable();
            $table->json('data')->nullable();
            $table->text('recording_url')->nullable();
            $table->string('ended_reason')->nullable();
            $table->json('context')->nullable(); // browser calls: the model's own history
            $table->timestamps();
            $table->index(['voice_agent_id', 'created_at']);
        });

        Schema::create('voice_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voice_agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('voice_call_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('service')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->string('requested_time')->nullable(); // as the caller said it
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('booked'); // booked | done | cancelled
            $table->timestamps();
            $table->index('starts_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_bookings');
        Schema::dropIfExists('voice_calls');
        Schema::dropIfExists('voice_agents');
    }
};
