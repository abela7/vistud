<?php

namespace Tests\Feature\Web;

use App\Appearance\Share;
use App\Study\Workspaces;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * How ViStud shows up outside itself (App\Appearance\Share, the owner's ask, 2026-10-23): search results, a shared
 * link's preview, the browser bar and the home screen. Only the signed-out pages anyone may see are listed.
 */
class ShareTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_the_login_page_is_listed_with_its_description_preview_and_icons(): void
    {
        $bar = Share::barColors();
        $this->get(route('login'))->assertOk()
            ->assertSee('<title>Log in · ViStud</title>', false)
            ->assertSee('<meta name="description" content="'.e(Share::DESCRIPTION).'">', false)
            ->assertSee('<meta name="robots" content="index, follow">', false)
            ->assertSee('<link rel="canonical" href="'.route('login').'">', false)
            ->assertSee('<meta property="og:title" content="ViStud: Organise your study. Ace your exams.">', false)
            ->assertSee('<meta property="og:url" content="'.route('login').'">', false)
            ->assertSee('<meta property="og:image" content="'.asset('brand/share.png').'">', false)
            ->assertSee('<meta name="twitter:card" content="summary_large_image">', false)
            ->assertSee('<meta name="theme-color" media="(prefers-color-scheme: light)" content="'.$bar['light'].'">', false)
            ->assertSee('<meta name="theme-color" media="(prefers-color-scheme: dark)" content="'.$bar['dark'].'">', false)
            ->assertSee('<link rel="manifest" href="'.route('manifest').'">', false)
            ->assertHeaderMissing('X-Robots-Tag');
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $bar['light']);

        $this->get(route('privacy'))->assertOk()
            ->assertSee('<meta name="robots" content="index, follow">', false)
            ->assertSee('<meta property="og:title" content="ViStud privacy policy">', false);
    }

    public function test_an_invitations_preview_says_what_it_is_and_nothing_of_who_or_which_class_and_is_not_listed(): void
    {
        $this->get(route('invitations.show'))->assertOk()
            ->assertSee('<meta property="og:title" content="You&#039;re invited to ViStud">', false)
            ->assertSee('<meta property="og:description" content="'.e(Share::JOIN).'">', false)
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertDontSee('rel="canonical"', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get(route('class-links.show'))->assertOk()
            ->assertSee('<meta property="og:title" content="Join your class on ViStud">', false);
    }

    public function test_every_private_page_says_noindex_in_its_head_and_its_header(): void
    {
        $user = $this->student(['name' => 'Ada Lovelace']);
        $course = app(Workspaces::class)->create($this->principal($user), ['name' => 'Secret course']);
        $this->actingAs($user);

        foreach ([route('home'), route('workspaces.show', $course->id), route('settings'), route('vistud-ai')] as $url) {
            $page = $this->get($url)->assertOk()
                ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
                ->assertDontSee('rel="canonical"', false);
            // A shared private link's preview is ViStud's own words, never the page's.
            $page->assertSee('<meta property="og:description" content="'.e(Share::DESCRIPTION).'">', false);
        }
    }

    public function test_robots_sitemap_and_manifest_are_made_from_the_sites_own_address(): void
    {
        $robots = $this->get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->getContent();
        $this->assertStringContainsString("Allow: /login\n", $robots);
        foreach (['/courses', '/admin', '/ai', '/notes', '/files', '/settings', '/api'] as $private) {
            $this->assertStringContainsString("Disallow: {$private}\n", $robots);
        }
        $this->assertStringContainsString('Sitemap: '.route('sitemap'), $robots);
        $this->assertStringNotContainsString("Disallow: /\n", $robots);

        $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('<loc>'.route('login').'</loc>', $sitemap);
        $this->assertStringContainsString('<loc>'.route('privacy').'</loc>', $sitemap);
        $this->assertSame(2, substr_count($sitemap, '<loc>'));

        $manifest = $this->get('/manifest.webmanifest')->assertOk()->json();
        $this->assertSame(['ViStud', 'standalone', '/'], [$manifest['name'], $manifest['display'], $manifest['start_url']]);
        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(str_replace(asset(''), '', $icon['src'])));
        }

        // The preview picture is the size every app expects.
        $this->assertSame([1200, 630], array_slice(getimagesize(public_path(Share::IMAGE)), 0, 2));
        $this->assertFileDoesNotExist(public_path('robots.txt'), 'a file would be served instead of the made one');
    }
}
