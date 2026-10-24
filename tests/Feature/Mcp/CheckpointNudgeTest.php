<?php

namespace Tests\Feature\Mcp;

use App\Study\Modules;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Tests\Concerns\ConnectsAiApps;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * A chat app's save says when a checkpoint is due (20 minutes without one), so the next tutor can pick up from
 * ViStud; and the rules every app gets say how to be the student's tutor (the owner's ask, 2026-10-24).
 */
class CheckpointNudgeTest extends TestCase
{
    use ConnectsAiApps, CreatesAccounts, RefreshesDatabase;

    public function test_a_save_reminds_the_app_of_a_checkpoint_after_twenty_minutes_and_not_after_one(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00:00', 'UTC'));
        $ada = $this->student();
        $by = $this->principal($ada);
        $course = app(Workspaces::class)->create($by, ['name' => 'Databases'])->id;
        $module = app(Modules::class)->create($by, $course, ['title' => 'Workshop A'])->id;
        $topic = app(Topics::class)->create($by, $course, '2NF', $module)->id;
        $session = app(Sessions::class)->start($by, $course, $topic, $module)->id;
        $token = $this->connectApp($ada, 'write')['access_token'];
        $card = fn (string $front) => $this->callTool($token, 'make_flashcards', ['session_id' => $session, 'cards' => [['front' => $front, 'back' => 'An answer.']]])->assertOk();

        // Just started: no reminder.
        $this->assertStringNotContainsString('Reminder', (string) $card('What is 2NF?')->json('result.content.0.text'));

        // Twenty-five minutes on with no checkpoint: the save says so.
        $this->travel(25)->minutes();
        $saved = $card('What is a partial dependency?');
        $this->assertStringContainsString('Reminder: no checkpoint for 20+ minutes. Call set_checkpoint now', (string) $saved->json('result.content.0.text'));
        $this->assertSame('set_checkpoint', $saved->json('result.structuredContent.next'));

        // A checkpoint, then the next save is quiet again.
        $this->callTool($token, 'set_checkpoint', ['session_id' => $session, 'checkpoint' => 'Finished 2NF; next, 3NF.'])->assertOk();
        $this->assertStringNotContainsString('Reminder', (string) $card('What is 3NF?')->json('result.content.0.text'));
    }

    public function test_the_rules_every_app_gets_say_how_to_be_the_students_tutor(): void
    {
        $rules = (string) file_get_contents(resource_path('prompts/mcp-rules.md'));
        foreach (['Be their tutor', 'save as you go', '`set_topic_status` confusing and `add_questions`', '`set_checkpoint`', '`end_session` with a summary written for the next tutor', 'pass `module_id`'] as $line) {
            $this->assertStringContainsString($line, $rules);
        }
    }
}
