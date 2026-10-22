<?php

namespace Tests\Feature\Engine;

use App\Engine\Context\Stack;
use App\Engine\Context\StudentName;
use App\Engine\CourseGuide;
use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\SessionChat;
use App\Engine\Settings;
use App\Engine\Vistud\Overview;
use App\Engine\VistudAi;
use App\Study\Modules;
use App\Study\Sessions;
use App\Study\Workspaces;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * Every model that talks with the student knows their first name and how to use it, or asks what to call them, and
 * asks where and what they study when no one knows (the owner's ask, 2026-10-22). Only the first name is sent.
 */
class StudentNameTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    public function test_only_a_real_first_name_is_taken_from_the_account(): void
    {
        $this->assertSame('Abel', StudentName::first('Abel Demssie'));
        $this->assertSame('Ada', StudentName::first('  Ada   Lovelace '));
        $this->assertSame('Zoë', StudentName::first('Zoë'));
        $this->assertSame("O'Neil", StudentName::first("O'Neil Smith"));
        // A title is not what they are called.
        $this->assertSame('Jane', StudentName::first('Miss Jane Doe'));
        $this->assertSame('Ada', StudentName::first('Prof. Dr. Ada Lovelace'));
        $this->assertNull(StudentName::first('F'));
        $this->assertNull(StudentName::first(''));
        $this->assertNull(StudentName::first('ada@example.test'));
    }

    public function test_a_known_name_is_used_now_and_then_and_an_unknown_one_is_asked_for(): void
    {
        $known = implode("\n", StudentName::lines('Ada', true));
        $this->assertStringContainsString('Their name: Ada', $known);
        $this->assertStringContainsString('not in every message', $known);
        $this->assertStringContainsString('keep it with remember, for all courses', $known);
        $this->assertStringContainsString('where they study and what', $known);

        $unknown = implode("\n", StudentName::lines(null, false));
        $this->assertStringContainsString("You don't know their name yet", $unknown);
        $this->assertStringContainsString('ask what they would like to be called', $unknown);
        // Without the remember tool, it isn't told to keep it.
        $this->assertStringNotContainsString('remember', $unknown);
    }

    public function test_every_chat_knows_the_first_name_and_never_the_whole_name_or_email(): void
    {
        $this->app->instance(Engine::class, $engine = new Fake);
        $user = $this->student(['name' => 'Ada Lovelace']);
        $by = $this->principal($user);
        $this->ownAiKey($by);
        app(Settings::class)->set($by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => true]);
        $course = app(Workspaces::class)->create($by, ['name' => 'Physics']);
        $module = app(Modules::class)->create($by, $course->id, ['title' => 'Week 1']);
        $session = app(Sessions::class)->start($by, $course->id);

        $prompts = [
            'session' => app(Stack::class)->build($by, $session, null, true)->system,
            'helper' => app(Stack::class)->helper($by, $module->id, [])->system,
            'vistud' => app(Overview::class)->build($by),
        ];
        app(SessionChat::class)->send($by, $session->id, 'Hello');
        $prompts['session chat'] = $engine->last()->system;
        app(VistudAi::class)->start($by, 'Hello');
        $prompts['vistud chat'] = $engine->last()->system;
        app(CourseGuide::class)->turn($by, $course->id, [], 'Hello');
        $prompts['course guide'] = $engine->last()->system;

        foreach ($prompts as $where => $prompt) {
            $this->assertStringContainsString('Their name: Ada', $prompt, $where);
            $this->assertStringNotContainsString('Lovelace', $prompt, $where);
            $this->assertStringNotContainsString((string) $user->email, $prompt, $where);
        }
        // The chats that can remember are told to ask where and what they study.
        $this->assertStringContainsString('where they study and what', $prompts['session']);
        $this->assertStringContainsString('where they study and what', $prompts['vistud']);
    }

    public function test_without_a_name_the_chat_asks_for_one(): void
    {
        $user = $this->student(['name' => 'F']);
        $by = $this->principal($user);
        $this->ownAiKey($by);
        app(Settings::class)->set($by, ['tutor_model' => 'fake/tutor', 'consent' => true]);

        $overview = app(Overview::class)->build($by);
        $this->assertStringContainsString("You don't know their name yet", $overview);
        $this->assertStringContainsString('keep it with remember, for all courses', $overview);
    }
}
