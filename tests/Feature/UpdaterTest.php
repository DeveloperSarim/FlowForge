<?php

namespace Tests\Feature;

use App\Domain\System\SystemSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;
use ZipArchive;

class UpdaterTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        // A throwaway "installed site" so the updater never touches this repository.
        $this->root = sys_get_temp_dir().'/ff-update-'.uniqid();
        $files = ['VERSION' => "1.1.0\n", '.env' => 'APP_KEY=keep', 'app/Old.php' => '<?php // old', 'vendor/autoload.php' => '<?php // old vendor',
            'public/index.php' => '<?php // old', 'bootstrap/app.php' => '<?php // old', 'bootstrap/cache/packages.php' => '<?php return [];', 'storage/app/private/keep.txt' => 'user data'];
        foreach ($files as $path => $content) {
            File::ensureDirectoryExists(dirname("{$this->root}/$path"));
            File::put("{$this->root}/$path", $content);
        }
        config(['flowforge.update_root' => $this->root]);
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function package(array $files, ?array $manifest = ['product' => 'flowforge', 'version' => '1.2.0', 'php' => '8.2.0']): string
    {
        $path = "{$this->root}-pkg.zip";
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($manifest) {
            $zip->addFromString('flowforge-update.json', json_encode($manifest));
        }
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return tap((string) file_get_contents($path), fn () => @unlink($path));
    }

    /** Sends the zip in 2 chunks like the browser does; returns the last response. */
    private function upload(string $bytes)
    {
        $half = intdiv(strlen($bytes), 2);
        foreach ([substr($bytes, 0, $half), substr($bytes, $half)] as $i => $chunk) {
            $response = $this->post('/settings/updates/upload', ['upload_id' => 'test-upload-1', 'index' => $i, 'total' => 2, 'offset' => $i ? $half : 0,
                'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', $chunk)], ['Accept' => 'application/json']);
        }

        return $response;
    }

    public function test_uploaded_update_is_unpacked_swapped_in_and_finished_keeping_settings_and_data(): void
    {
        $this->upload($this->package([
            'VERSION' => "1.2.0\n", 'app/New.php' => '<?php // new', 'vendor/autoload.php' => '<?php // new vendor',
            'public/index.php' => '<?php // new', 'bootstrap/app.php' => '<?php // new',
        ]))->assertOk()->assertJsonPath('package.version', '1.2.0')->assertJsonPath('package.current', '1.1.0');

        $token = $this->postJson('/settings/updates/extract', ['from' => 0])->assertOk()->assertJsonPath('done', true)->json('token');

        $run = Process::run([PHP_BINARY, base_path('public/update-runner.php'), $token, $this->root]);
        $this->assertTrue($run->successful(), $run->output());
        $this->assertSame('1.2.0', trim(File::get("{$this->root}/VERSION")));
        $this->assertFileExists("{$this->root}/app/New.php");
        $this->assertFileDoesNotExist("{$this->root}/app/Old.php");
        $this->assertSame('<?php // new vendor', File::get("{$this->root}/vendor/autoload.php"));
        $this->assertSame('APP_KEY=keep', File::get("{$this->root}/.env"));
        $this->assertSame('user data', File::get("{$this->root}/storage/app/private/keep.txt"));
        $this->assertFileDoesNotExist("{$this->root}/bootstrap/cache/packages.php"); // rebuilt for the new vendor
        $this->assertCount(1, File::glob("{$this->root}/storage/app/updates/backup-1.1.0-*/app/Old.php"));

        $this->assertFalse(Process::run([PHP_BINARY, base_path('public/update-runner.php'), $token, $this->root])->successful(), 'tokens are single use');

        $this->postJson('/settings/updates/finish')->assertOk()->assertJson(['from' => '1.1.0', 'to' => '1.2.0']);
        $this->assertSame('1.2.0', app(SystemSettings::class)->get('update_history')[0]['to']);
        $this->assertDirectoryDoesNotExist("{$this->root}/storage/app/updates/staging");
    }

    public function test_wrong_packages_are_refused_with_a_clear_reason(): void
    {
        $this->upload($this->package(['artisan' => '#!/usr/bin/env php', 'vendor/autoload.php' => '<?php'], null))
            ->assertStatus(422)->assertJsonPath('message', 'This is the fresh-install zip. Upload the update zip instead (flowforge-update-….zip).');

        $this->upload($this->package(['vendor/autoload.php' => '<?php'], ['product' => 'flowforge', 'version' => '1.1.0']))
            ->assertStatus(422)->assertJsonPath('message', 'This server already runs 1.1.0; the package is 1.1.0. Upload a newer version.');

        $this->upload($this->package(['vendor/autoload.php' => '<?php', 'storage/app/evil.txt' => 'x']))
            ->assertStatus(422)->assertJsonPath('message', 'The package contains a file it must not touch (storage/app/evil.txt).');
    }

    private const REL = 'https://github.com/DeveloperSarim/FlowForge/releases/download/v1.2.0';

    /** GitHub's "latest release" answer for 1.2.0, with its update zip and checksums. */
    private function fakeGitHub(string $zip, ?string $sums): void
    {
        Http::fake([
            'api.github.com/repos/DeveloperSarim/FlowForge/releases/latest' => Http::response(['tag_name' => 'v1.2.0', 'body' => 'Faster runs.', 'published_at' => '2026-10-14T10:00:00Z',
                'html_url' => 'https://github.com/DeveloperSarim/FlowForge/releases/tag/v1.2.0', 'assets' => [
                    ['name' => 'flowforge-update-1.2.0.zip', 'browser_download_url' => self::REL.'/flowforge-update-1.2.0.zip'],
                    ['name' => 'SHA256SUMS.txt', 'browser_download_url' => self::REL.'/SHA256SUMS.txt'],
                ]]),
            self::REL.'/flowforge-update-1.2.0.zip' => Http::response($zip),
            self::REL.'/SHA256SUMS.txt' => Http::response($sums ?? '', $sums === null ? 404 : 200),
        ]);
    }

    public function test_settings_find_a_newer_release_on_github_and_show_the_badge(): void
    {
        $this->fakeGitHub('zip', null);

        $this->getJson('/settings/updates/check')->assertOk()
            ->assertJsonPath('available', true)->assertJsonPath('current', '1.1.0')
            ->assertJsonPath('latest.version', '1.2.0')->assertJsonPath('latest.notes', 'Faster runs.')
            ->assertJsonPath('latest.zip', self::REL.'/flowforge-update-1.2.0.zip');

        // The answer is cached, so the sidebar badge needs no network call.
        $this->get('/workflows')->assertInertia(fn ($page) => $page->where('updateAvailable', '1.2.0'));
    }

    public function test_the_check_falls_back_to_the_release_page_when_the_api_limit_is_used_up(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response(['message' => 'API rate limit exceeded'], 403),
            'github.com/DeveloperSarim/FlowForge/releases/latest' => Http::response('', 302, ['Location' => 'https://github.com/DeveloperSarim/FlowForge/releases/tag/v1.2.0']),
        ]);

        $this->getJson('/settings/updates/check?fresh=1')->assertOk()
            ->assertJsonPath('available', true)->assertJsonPath('latest.version', '1.2.0')
            ->assertJsonPath('latest.zip', self::REL.'/flowforge-update-1.2.0.zip');
    }

    public function test_one_click_update_downloads_verifies_and_stages_the_github_package(): void
    {
        $zip = $this->package(['VERSION' => "1.2.0\n", 'app/New.php' => '<?php // new', 'vendor/autoload.php' => '<?php // new vendor']);
        $this->fakeGitHub($zip, hash('sha256', $zip)."  flowforge-update-1.2.0.zip\n".str_repeat('0', 64)."  flowforge-install-1.2.0.zip\n");

        $this->postJson('/settings/updates/download')->assertOk()->assertJsonPath('package.version', '1.2.0')->assertJsonPath('package.current', '1.1.0');
        $this->postJson('/settings/updates/extract', ['from' => 0])->assertOk()->assertJsonPath('done', true);
        $this->assertFileExists("{$this->root}/storage/app/updates/staging/app/New.php");
    }

    public function test_a_download_with_the_wrong_checksum_is_thrown_away(): void
    {
        $zip = $this->package(['VERSION' => "1.2.0\n", 'vendor/autoload.php' => '<?php']);
        $this->fakeGitHub($zip, str_repeat('a', 64)."  flowforge-update-1.2.0.zip\n");

        $this->postJson('/settings/updates/download')->assertStatus(422)
            ->assertJsonPath('message', 'The downloaded package failed its checksum check, so it was not installed. Try again.');
        $this->assertFileDoesNotExist("{$this->root}/storage/app/updates/incoming/package.zip");
    }

    public function test_nothing_is_downloaded_when_this_is_already_the_latest_version(): void
    {
        File::put("{$this->root}/VERSION", "1.2.0\n");
        $this->fakeGitHub('zip', null);

        $this->postJson('/settings/updates/download')->assertStatus(422)->assertJsonPath('message', "You're already on the latest version (1.2.0).");
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '.zip'));
    }
}
