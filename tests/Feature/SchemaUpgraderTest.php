<?php

namespace Tests\Feature;

use App\Domain\System\SchemaUpgrader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaUpgraderTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_code_on_an_old_schema_migrates_itself_once(): void
    {
        $marker = sys_get_temp_dir().'/ff-schema-'.uniqid();
        config(['flowforge.schema_marker' => $marker]);

        // The state an interrupted update leaves: new code, the last migrations (team roles, API tokens, voice agents, retries) not run.
        Artisan::call('migrate:rollback', ['--step' => 4, '--force' => true]);
        $this->assertFalse(Schema::hasColumn('users', 'role'));
        $this->assertFalse(Schema::hasTable('api_tokens'));
        $this->assertFalse(Schema::hasTable('voice_agents'));
        $this->assertFalse(Schema::hasColumn('workflow_executions', 'retry_of'));

        SchemaUpgrader::ensureCurrent();

        $this->assertTrue(Schema::hasColumn('users', 'role'));
        $this->assertTrue(Schema::hasTable('api_tokens'));
        $this->assertTrue(Schema::hasTable('voice_calls'));
        $this->assertTrue(Schema::hasColumn('workflows', 'settings'));
        $this->assertSame(trim(file_get_contents(base_path('VERSION'))), file_get_contents($marker));

        Schema::drop('api_tokens'); // up to date now: a second call must not touch the database
        SchemaUpgrader::ensureCurrent();
        $this->assertFalse(Schema::hasTable('api_tokens'));
        @unlink($marker);
    }
}
