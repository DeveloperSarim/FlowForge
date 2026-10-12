<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('member')->after('theme'); // owner | admin | member
            $table->json('permissions')->nullable()->after('role');       // members only: workflows.view|edit|delete, ai
            $table->string('invite_token', 64)->nullable()->unique()->after('permissions'); // sha256 of the emailed token
            $table->timestamp('invite_expires_at')->nullable()->after('invite_token');
        });
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        // Installs so far had one administrator: the first account owns the install, any others stay admins.
        $owner = DB::table('users')->orderBy('id')->value('id');
        DB::table('users')->update(['role' => 'admin']);
        if ($owner) {
            DB::table('users')->where('id', $owner)->update(['role' => 'owner']);
            DB::table('ai_conversations')->whereNull('user_id')->update(['user_id' => $owner]);
        }
    }

    public function down(): void
    {
        Schema::table('ai_conversations', fn (Blueprint $table) => $table->dropConstrainedForeignId('user_id'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['role', 'permissions', 'invite_token', 'invite_expires_at']));
    }
};
