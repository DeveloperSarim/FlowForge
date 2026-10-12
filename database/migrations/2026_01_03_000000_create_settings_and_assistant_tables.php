<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // System-wide settings (SMTP, notifications, AI provider). Values are encrypted JSON.
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->longText('value');
            $table->timestamps();
        });

        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('title')->default('New chat');
            $table->string('provider', 20);
            $table->string('model', 120);
            $table->foreignId('workflow_id')->nullable()->constrained()->nullOnDelete();
            $table->json('history')->nullable();   // provider-native message history
            $table->json('display')->nullable();   // what the chat UI renders
            $table->json('usage')->nullable();     // running token totals
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_conversations');
        Schema::dropIfExists('settings');
    }
};
