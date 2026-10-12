<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_crawler_and_agent_files_carry_this_installs_domain(): void
    {
        $this->get('/robots.txt')->assertOk()->assertSee('Sitemap: '.url('/sitemap.xml'), false)->assertSee('Disallow: /workflows', false)->assertSee('Allow: /login', false);
        $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->assertSee('<loc>'.url('/login').'</loc>', false);
        $this->get('/llms.txt')->assertOk()->assertSee('# FlowForge', false)->assertSee(url('/api/mcp'), false)->assertSee('`save_workflow`', false);
        $this->get('/llms-full.txt')->assertOk()->assertSee('## App catalog', false)->assertSee('(`linkedin`)', false)->assertSee('### get_app', false);
    }

    public function test_only_the_sign_in_page_is_indexable_and_it_has_a_social_preview(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee('<meta name="robots" content="index, follow, max-image-preview:large">', false)
            ->assertSee('<meta property="og:image" content="'.asset('og-image.png').'">', false)
            ->assertSee('application/ld+json', false);

        $this->actingAs(User::factory()->create())->get('/settings')->assertOk()->assertSee('<meta name="robots" content="noindex, nofollow">', false);
        $this->assertFileExists(public_path('og-image.png'));
    }
}
