<?php

namespace Tests\Feature\Web;

use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** Styled 403 and 404 pages. They never reveal why the request was refused. */
class ErrorPagesTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private const LEAKED = 'You do not have permission to do this.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_a_student_opening_admin_sees_the_forbidden_page(): void
    {
        $this->actingAs($this->student())->get('/admin')
            ->assertForbidden()
            ->assertSee('You can\'t open this page')
            ->assertSee('Your account doesn\'t have access to it. If you think it should, ask an admin.', false)
            ->assertDontSee(self::LEAKED);
    }

    public function test_a_missing_page_shows_the_not_found_page(): void
    {
        $this->get('/no-such-page')
            ->assertNotFound()
            ->assertSee('Page not found')
            ->assertSee('This page doesn\'t exist, or it isn\'t available to you.', false)
            ->assertDontSee(self::LEAKED);
    }
}
