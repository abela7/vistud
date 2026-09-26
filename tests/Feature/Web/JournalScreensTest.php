<?php

namespace Tests\Feature\Web;

use App\Brain\Writer\JournalWriter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Tests\Concerns\BuildsJournalEntries;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The student's journal pages (WP6; ADR 0003 §10.4 and T3's web cases). */
class JournalScreensTest extends TestCase
{
    use BuildsJournalEntries, CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->ada = $this->student(['name' => 'Ada Lovelace']);
        $this->bob = $this->student(['name' => 'Bob Babbage']);

        foreach ([$this->ada, $this->bob] as $student) {
            $scope = $this->learnerScopeOf($student);
            $who = $student->is($this->ada) ? 'ADA' : 'BOB';
            app(JournalWriter::class)->appendBatch($scope, [
                ...$this->setupEntries(),
                $this->attempt($scope, "E-{$who}", 'TK-1', 'incorrect', '2026-10-15T14:12:00+01:00', ['content' => ['answer' => "CANARY-{$who}"]]),
            ]);
        }
    }

    public function test_a_student_sees_their_journal_newest_first_and_opens_an_entry(): void
    {
        $this->actingAs($this->ada)->get('/journal')
            ->assertOk()
            ->assertSee('<title>Journal', false)
            ->assertSeeInOrder(['Attempt', 'Incorrect', 'Task TK-1', 'Task'])
            ->assertSee(route('journal.show', 'E-ADA'), false)
            ->assertDontSee('E-BOB');

        $this->actingAs($this->ada)->get('/journal/E-ADA')
            ->assertOk()
            ->assertSee('aria-current="page"', false)
            ->assertSeeInOrder(['Attempt', 'Incorrect', 'recorded by You', 'Answer', 'CANARY-ADA', 'Details', 'Outcome', 'incorrect'])
            ->assertDontSee('CANARY-BOB');
    }

    public function test_another_students_entry_is_exactly_as_missing_as_one_that_does_not_exist(): void
    {
        $theirs = $this->actingAs($this->ada)->get('/journal/E-BOB');
        $missing = $this->actingAs($this->ada)->get('/journal/E-NOBODY');

        $theirs->assertNotFound()->assertDontSee('CANARY-BOB');
        $missing->assertNotFound();
        $this->assertSame($missing->getContent(), $theirs->getContent());
    }

    public function test_tampering_with_the_pages_livewire_request_is_also_not_found(): void
    {
        $page = $this->actingAs($this->ada)->get('/journal/E-ADA')->getContent();
        preg_match('/wire:snapshot="([^"]+)"/', $page, $match);
        $snapshot = html_entity_decode($match[1]);
        $uri = app(HandleRequests::class)->getUpdateUri();
        $send = fn (string $snapshot, array $updates) => $this->actingAs($this->ada)->postJson($uri, [
            'components' => [['snapshot' => $snapshot, 'updates' => $updates, 'calls' => []]],
        ], ['X-Livewire' => '1']);

        $send($snapshot, [])->assertOk()->assertSee('CANARY-ADA');
        // The locked ID can't be changed...
        $send($snapshot, ['entryId' => 'E-BOB'])->assertNotFound()->assertDontSee('CANARY-BOB');
        // ...and a snapshot edited by hand fails its checksum.
        $send(str_replace('E-ADA', 'E-BOB', $snapshot), [])->assertNotFound()->assertDontSee('CANARY-BOB');
    }

    public function test_an_account_without_a_journal_gets_forbidden_and_guests_log_in(): void
    {
        $this->actingAs(User::factory()->admin()->twoFactor()->create()->fresh())->get('/journal')->assertForbidden();
        $this->actingAs(User::factory()->admin()->twoFactor()->create()->fresh())->get('/journal/E-ADA')->assertForbidden();

        auth()->logout();
        $this->get('/journal')->assertRedirect(route('login'));
    }

    public function test_an_empty_journal_says_so(): void
    {
        $this->actingAs($this->student())->get('/journal')->assertOk()->assertSee('Nothing recorded yet.');
    }

    public function test_the_sample_command_fills_an_empty_journal_once_and_never_in_production(): void
    {
        $carol = $this->student(['email' => 'carol@example.test']);

        $this->artisan('vistud:journal:sample', ['email' => 'carol@example.test'])->assertSuccessful();
        $this->assertSame(5, DB::table('learners')->where('user_id', $carol->id)->value('journal_position'));
        $this->artisan('vistud:journal:sample', ['email' => 'carol@example.test'])->assertFailed();

        $this->app['env'] = 'production';
        $this->artisan('vistud:journal:sample', ['email' => $this->student()->email])->assertFailed();
    }
}
