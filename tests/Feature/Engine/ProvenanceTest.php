<?php

namespace Tests\Feature\Engine;

use App\Brain\Store\JournalReader;
use App\Engine\Context\Stack;
use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\SessionChat;
use App\Engine\Settings;
use App\Engine\Toolbox;
use App\Engine\Tools\Context;
use App\Models\User;
use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Study\Modules;
use App\Study\Quizzes;
use App\Study\SessionDetails;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use App\Study\WriteBack;
use Carbon\CarbonImmutable;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * What an AI judged goes into the journal with who judged it: the model, the version of the rules it was given and
 * how it came, so one model's verdicts can be told from another's in a later session; and what no AI could mark
 * stays unmarked, never guessed.
 */
class ProvenanceTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private WorkspaceDetails $databases;

    private SessionDetails $session;

    private Fake $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
        $this->engine = new Fake;
        $this->app->instance(Engine::class, $this->engine);
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->databases = app(Workspaces::class)->create($this->by, ['name' => 'Databases']);
        $week2 = app(Modules::class)->create($this->by, $this->databases->id, ['title' => 'Week 2: SQL joins'])->id;
        $joins = app(Topics::class)->create($this->by, $this->databases->id, 'Joins', $week2)->id;
        $this->session = app(Sessions::class)->start($this->by, $this->databases->id, $joins, $week2);
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        $this->ownAiKey($this->by);
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => true]);
    }

    /** @return list<array<string, mixed>> the bodies of the journal's attempts, in order */
    private function attempts(): array
    {
        return LearnerTables::query(Guard::learner($this->by), 'journal_entries')->where('kind', 'attempt')->orderBy('position')
            ->pluck('body')->map(fn ($body) => json_decode((string) $body, true))->all();
    }

    public function test_a_quiz_the_tutor_records_in_the_chat_carries_its_model_and_rules(): void
    {
        $this->engine->will(
            Fake::calls('record_quiz', ['kind' => 'quiz', 'questions' => [['asked' => 'What does a left join keep?', 'answer' => 'Every left row.', 'result' => 'correct']]]),
            Fake::says('Recorded.'),
        );
        app(SessionChat::class)->send($this->by, $this->session->id, 'Quiz me.');

        $attempt = $this->attempts()[0];
        $this->assertSame(['ai', 'correct'], [$attempt['judged_by'], $attempt['outcome']]);
        $this->assertEqualsCanonicalizing(['model' => 'fake/tutor', 'prompt' => Stack::promptVersion(), 'via' => 'chat'], $attempt['judge']);
        $this->assertMatchesRegularExpression('/^tutor-2@[0-9a-f]{12}$/', $attempt['judge']['prompt']);
    }

    public function test_answers_pasted_back_from_another_ai_say_so(): void
    {
        $writeBack = app(WriteBack::class);
        $items = $writeBack->review($this->by, $this->session->id, '<attempt topic="Joins" form="recall" support="unaided" result="partial"><asked>What is a join?</asked><answer>Tables together.</answer></attempt>');
        $writeBack->apply($this->by, $this->session->id, $items);

        $this->assertEqualsCanonicalizing(WriteBack::PASTED, $this->attempts()[0]['judge']);
    }

    public function test_an_answer_no_ai_could_mark_is_kept_unmarked_and_left_out_of_the_score(): void
    {
        $quiz = app(Quizzes::class)->record($this->by, $this->session->id, ['kind' => 'quiz', 'questions' => [
            ['asked' => 'What does a left join keep?', 'answer' => 'Every left row.', 'result' => 'correct'],
            ['asked' => 'And a right join?', 'answer' => 'A long answer the grader never saw.', 'result' => 'unjudged'],
        ]], ['model' => 'fake/quick', 'prompt' => 'quiz-panel@1', 'via' => 'quiz']);

        $this->assertSame(100, $quiz->score);
        $this->assertSame(['correct', 'unjudged'], array_column($this->attempts(), 'outcome'));
        $this->assertSame('quiz', $this->attempts()[1]['judge']['via']);
    }

    public function test_mcp_judge_via_and_actor_channel_are_accepted_and_kept(): void
    {
        $mcp = $this->principal($this->ada, channel: 'mcp');
        $context = new Context($this->databases->id, $this->session->moduleId, $this->session->id, 'UTC', model: 'anthropic/claude', via: 'mcp', prompt: 'mcp-rules@1');
        $this->assertSame(['model' => 'anthropic/claude', 'prompt' => 'mcp-rules@1', 'via' => 'mcp'], $context->judge());

        app(Toolbox::class)->run($mcp, $context, 'record_quiz', [
            'kind' => 'quiz',
            'questions' => [['asked' => 'What is a left join?', 'answer' => 'Keeps all left rows.', 'result' => 'correct', 'topic' => 'Joins']],
        ]);

        $entries = app(JournalReader::class)->entries(Guard::learner($mcp));
        $attempt = collect($entries)->last(fn ($e) => $e->type === 'core.attempt');
        $this->assertNotNull($attempt);
        $this->assertSame('mcp', $attempt->actor->channel);
        $this->assertEqualsCanonicalizing(['model' => 'anthropic/claude', 'prompt' => 'mcp-rules@1', 'via' => 'mcp'], $attempt->body['judge']);

        app(Sessions::class)->end($mcp, $this->session->id);
        $entries = app(JournalReader::class)->entries(Guard::learner($mcp));
        $sessionRecord = collect($entries)->last(fn ($e) => $e->type === 'core.record');
        $this->assertSame('mcp', $sessionRecord->actor->channel);
        $this->assertSame('mcp', $sessionRecord->body['channel']);
    }
}
