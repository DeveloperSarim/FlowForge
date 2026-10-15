<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Automatic retries: workflow settings, and which try of a run an execution is. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->json('settings')->nullable();
        });
        Schema::table('workflow_executions', function (Blueprint $table) {
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->unsignedBigInteger('retry_of')->nullable()->index(); // the failed run this one resumes
            $table->timestamp('retry_at')->nullable(); // set while an automatic retry of this failed run is waiting
        });
    }

    public function down(): void
    {
        Schema::table('workflow_executions', function (Blueprint $table) {
            $table->dropIndex(['retry_of']);
            $table->dropColumn(['attempt', 'retry_of', 'retry_at']);
        });
        Schema::table('workflows', function (Blueprint $table) {
            $table->dropColumn('settings');
        });
    }
};
