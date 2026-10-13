<?php

namespace Tests\Feature\Web;

use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Settings;
use App\Engine\Tools\Context;
use App\Engine\Tools\WriteNoteTool;
use App\Livewire\Workspaces\StudySession;
use App\Models\User;
use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Ids;
use App\Study\Flashcards;
use App\Study\Modules;
use App\Study\NoteDoc;
use App\Study\Notes;
use App\Study\Questions;
use App\Study\Quizzes;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

class SessionAllInOnePanesTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private Fake $engine;

    private string $workspace;

    private string $module;

    private string $joins;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 09:00:00', 'UTC'));
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        $this->engine = new Fake;
        $this->app->instance(Engine::class, $this->engine);
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->workspace = app(Workspaces::class)->create($this->by, ['name' => 'Databases'])->id;
        $this->module = app(Modules::class)->create($this->by, $this->workspace, ['title' => 'Week 2: SQL joins'])->id;
        $this->joins = app(Topics::class)->create($this->by, $this->workspace, 'Joins', $this->module)->id;
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => true]);
        $this->actingAs($this->ada);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));
    }

    public function test_all_six_panes_exist_in_the_split_screen_switcher(): void
    {
        $session = app(Sessions::class)->start($this->by, $this->workspace, topicId: $this->joins);

        Livewire::test(StudySession::class, ['workspaceId' => $this->workspace, 'sessionId' => $session->id])
            ->assertSee('AI Chat')
            ->assertSee('Note')
            ->assertSee('File')
            ->assertSee('Questions')
            ->assertSee('Quiz')
            ->assertSee('Cards');
    }

    public function test_flashcards_pane_displays_cards_and_rates_recall(): void
    {
        $session = app(Sessions::class)->start($this->by, $this->workspace, topicId: $this->joins);
        $cardsService = app(Flashcards::class);
        $cardId = $cardsService->add($this->by, $this->workspace, $this->joins, 'What is an INNER JOIN?', 'Returns rows with matching values in both tables.');

        $comp = Livewire::test(StudySession::class, ['workspaceId' => $this->workspace, 'sessionId' => $session->id])
            ->assertSee('What is an INNER JOIN?')
            ->assertSee('Returns rows with matching values in both tables.')
            ->assertSee('Got it')
            ->assertSee('Partly')
            ->assertSee('Not yet');

        // Test answering a card
        $comp->call('answerCard', $cardId, 'correct')
            ->assertDispatched('flashcards-changed');

        $updatedCard = $cardsService->find($this->by, $cardId);
        $this->assertSame('correct', $updatedCard->lastResult);
        $this->assertSame(1, $updatedCard->reviews);

        // A card the student doesn't want: deleted for good from the pane, and the deck is empty again.
        $comp->assertSee('Delete card')
            ->call('deleteCard', $cardId)
            ->assertSee('Card deleted.')
            ->assertDispatched('flashcards-changed')
            ->assertDontSee('What is an INNER JOIN?')
            ->assertSee('No flashcards yet');
        $this->assertSame([], $cardsService->list($this->by, $this->workspace));
    }

    public function test_questions_pane_displays_and_resolves_questions(): void
    {
        $session = app(Sessions::class)->start($this->by, $this->workspace, topicId: $this->joins);
        $questionsService = app(Questions::class);
        $q = $questionsService->ask($this->by, $this->workspace, 'Why use LEFT JOIN instead of INNER JOIN?', $this->joins, $this->module, $session->id);

        $comp = Livewire::test(StudySession::class, ['workspaceId' => $this->workspace, 'sessionId' => $session->id])
            ->assertSee('Why use LEFT JOIN instead of INNER JOIN?')
            ->assertSee('Understood')
            ->assertSee('Ask in chat');

        // Resolve question
        $comp->call('resolveQuestion', $q->id)
            ->assertDispatched('questions-changed');

        $updated = $questionsService->find($this->by, $q->id);
        $this->assertSame('answered', $updated->status);
    }

    public function test_quizzes_pane_displays_recorded_quizzes(): void
    {
        $session = app(Sessions::class)->start($this->by, $this->workspace, topicId: $this->joins);
        $quizzesService = app(Quizzes::class);
        $quiz = $quizzesService->record($this->by, $session->id, [
            'kind' => 'quiz',
            'topic' => 'Joins',
            'questions' => [
                [
                    'asked' => 'Which join returns all rows from the left table?',
                    'answer' => 'LEFT JOIN',
                    'result' => 'correct',
                    'right' => 'LEFT JOIN',
                    'fix' => 'Spot on!',
                    'topic' => 'Joins',
                ],
            ],
        ]);

        Livewire::test(StudySession::class, ['workspaceId' => $this->workspace, 'sessionId' => $session->id])
            ->assertSee('Quizzes')
            ->assertSee('100%')
            ->assertSee('Which join returns all rows from the left table?')
            ->assertSee('Review with AI');
    }

    public function test_questions_pane_allows_saving_quick_answer_and_generating_concise_answer(): void
    {
        $session = app(Sessions::class)->start($this->by, $this->workspace, topicId: $this->joins);
        $questionsService = app(Questions::class);
        $q = $questionsService->ask($this->by, $this->workspace, 'What is a CROSS JOIN?', $this->joins, $this->module, $session->id);

        $comp = Livewire::test(StudySession::class, ['workspaceId' => $this->workspace, 'sessionId' => $session->id]);

        // 1. Test saving a quick answer manually
        $comp->call('saveQuickAnswer', $q->id, 'A Cartesian product of two tables.')
            ->assertDispatched('questions-changed');

        $updated = $questionsService->find($this->by, $q->id);
        $this->assertSame('answered', $updated->status);
        $this->assertSame('A Cartesian product of two tables.', $updated->answer);

        // 2. Test generating a concise answer with AI
        $q2 = $questionsService->ask($this->by, $this->workspace, 'What is an OUTER JOIN?', $this->joins, $this->module, $session->id);
        $this->engine->will(Fake::says('An outer join preserves rows from one or both tables even when no match exists.', 1_000, 'fake/quick'));

        $comp->call('generateConciseAnswer', $q2->id)
            ->assertDispatched('questions-changed');

        $updated2 = $questionsService->find($this->by, $q2->id);
        $this->assertSame('answered', $updated2->status);
        $this->assertSame('An outer join preserves rows from one or both tables even when no match exists.', $updated2->answer);

        // 3. Test drafting a concise answer
        $this->engine->will(Fake::says('Drafted concise answer.', 1_000, 'fake/quick'));
        $draft = $comp->instance()->draftConciseAnswer($q2->id);
        $this->assertSame('Drafted concise answer.', $draft);
    }

    public function test_in_pane_quiz_can_be_configured_and_started_with_ai(): void
    {
        $session = app(Sessions::class)->start($this->by, $this->workspace, topicId: $this->joins);

        $comp = Livewire::test(StudySession::class, ['workspaceId' => $this->workspace, 'sessionId' => $session->id]);

        // Open config
        $comp->call('openQuizConfigure', 'custom')
            ->assertSet('quizView', 'configure')
            ->assertSee('Customize Your Quiz');

        // Set configuration options: 3 questions, best (MCQ), hard
        $comp->set('quizCount', 3)
            ->set('quizFormat', 'best')
            ->set('quizLevel', 'hard');

        // Mock AI generator returning 3 questions
        $sampleQuizJson = json_encode([
            [
                'type' => 'best',
                'asked' => 'Which join returns non-matching rows from the left table?',
                'options' => ['A' => 'INNER JOIN', 'B' => 'LEFT JOIN', 'C' => 'RIGHT JOIN', 'D' => 'CROSS JOIN'],
                'right' => 'B',
                'explanation' => 'LEFT JOIN preserves all rows from the left table.',
                'topic' => 'Joins',
            ],
            [
                'type' => 'best',
                'asked' => 'What is the result size of a CROSS JOIN between tables of size M and N?',
                'options' => ['A' => 'M + N', 'B' => 'M * N', 'C' => 'Max(M, N)', 'D' => 'Min(M, N)'],
                'right' => 'B',
                'explanation' => 'A Cartesian product yields M * N rows.',
                'topic' => 'Joins',
            ],
            [
                'type' => 'best',
                'asked' => 'Which clause specifies join predicates in standard SQL?',
                'options' => ['A' => 'WHERE only', 'B' => 'ON', 'C' => 'HAVING', 'D' => 'GROUP BY'],
                'right' => 'B',
                'explanation' => 'ON defines the join predicate.',
                'topic' => 'Joins',
            ],
        ]);
        $this->engine->will(Fake::says($sampleQuizJson, 1_000, 'fake/quick'));

        $comp->call('startConfiguredQuiz')
            ->assertSet('quizView', 'taking')
            ->assertCount('activeQuizQuestions', 3)
            ->assertSee('Which join returns non-matching rows from the left table?')
            ->assertSee('LEFT JOIN');

        // Answering question 0 auto-advances to question 1
        $comp->call('selectQuizOption', 0, 'B')
            ->assertSet('activeQuizAnswers.0', 'B')
            ->assertSet('activeQuizIndex', 1)
            ->assertSee('What is the result size of a CROSS JOIN between tables of size M and N?');

        // Can go back to question 0 and change answer
        $comp->call('prevQuizQuestion')
            ->assertSet('activeQuizIndex', 0)
            ->assertSee('Which join returns non-matching rows from the left table?')
            ->call('selectQuizOption', 0, 'A')
            ->assertSet('activeQuizAnswers.0', 'A')
            ->assertSet('activeQuizIndex', 1);

        // Answering question 1 auto-advances to question 2 (the final question)
        $comp->call('selectQuizOption', 1, 'B')
            ->assertSet('activeQuizAnswers.1', 'B')
            ->assertSet('activeQuizIndex', 2)
            ->assertSee('Which clause specifies join predicates in standard SQL?');

        // Answering the final question stays on final question and shows Submit & Grade
        $comp->call('selectQuizOption', 2, 'B')
            ->assertSet('activeQuizAnswers.2', 'B')
            ->assertSet('activeQuizIndex', 2)
            ->assertSee('Submit & Grade', false);

        $this->assertTrue($comp->instance()->allQuestionsAnswered());
    }

    public function test_in_pane_quiz_can_be_submitted_graded_and_turn_question_into_real_question(): void
    {
        $session = app(Sessions::class)->start($this->by, $this->workspace, topicId: $this->joins);

        $comp = Livewire::test(StudySession::class, ['workspaceId' => $this->workspace, 'sessionId' => $session->id]);

        // Start quick recap (fallback or AI)
        $comp->call('startQuickRecap')
            ->assertSet('quizView', 'taking');

        // Answer question 0 with 'A'
        $comp->call('selectQuizOption', 0, 'A');

        // Submit quiz with 45 seconds duration
        $comp->call('submitQuiz', 45)
            ->assertDispatched('quizzes-changed')
            ->assertSet('quizView', 'reviewed')
            ->assertSee('45s')
            ->assertSee('Saved to study session records');

        $latest = $comp->get('latestQuizResult');
        $this->assertNotNull($latest);
        $this->assertSame(45, $latest['durationSeconds']);

        // Check quiz was saved in database
        $quizzes = app(Quizzes::class)->forSession($this->by, $session->id);
        $this->assertCount(1, $quizzes);
        $this->assertSame(45, $quizzes[0]->durationSeconds());

        // Test turning a quiz question into a real question
        $firstQ = $latest['questions'][0]['asked'];
        $comp->call('turnQuizQuestionIntoQuestion', $firstQ, $latest['questions'][0]['right'], 'Joins')
            ->assertDispatched('questions-changed');

        $questionsService = app(Questions::class);
        $saved = collect($questionsService->list($this->by, $this->workspace))->firstWhere('text', $firstQ);
        $this->assertNotNull($saved);
        $this->assertSame('answered', $saved->status);
    }

    public function test_study_session_synchronizes_active_note_and_engine_thread_uses_it_when_writing(): void
    {
        $session = app(Sessions::class)->start($this->by, $this->workspace, topicId: $this->joins);
        $notesService = app(Notes::class);
        $note = $notesService->createWritten($this->by, 'workspace', $this->workspace, [
            'create_id' => Ids::new(),
            'title' => 'My Opened Lecture Note',
            'doc' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Original text.']]]]],
        ]);

        $comp = Livewire::test(StudySession::class, ['workspaceId' => $this->workspace, 'sessionId' => $session->id]);
        $comp->call('setActiveNote', $note->id);
        $this->assertSame($note->id, $comp->get('activeNoteId'));

        $scope = Guard::learner($this->by);
        $savedNoteId = LearnerTables::query($scope, 'engine_threads')
            ->where('session_id', $session->id)
            ->value('note_id');
        $this->assertSame($note->id, $savedNoteId);

        // When the AI tutor writes a note, it automatically targets this opened note
        $tool = app(WriteNoteTool::class);
        $context = new Context(workspaceId: $this->workspace, moduleId: $this->module, sessionId: $session->id, zone: 'UTC');
        $result = $tool->run($this->by, $context, ['text' => 'New key points written by AI tutor.']);

        $this->assertStringContainsString('My Opened Lecture Note', $result);
        $updatedNote = $notesService->open($this->by, $note->id);
        $text = NoteDoc::text($updatedNote->doc);
        $this->assertStringContainsString('New key points written by AI tutor', $text);
    }

    public function test_in_note_ask_ai_endpoint_returns_quick_focused_answer(): void
    {
        $notesService = app(Notes::class);
        $note = $notesService->createWritten($this->by, 'workspace', $this->workspace, [
            'create_id' => Ids::new(),
            'title' => 'Database Normalization',
            'doc' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Third normal form requires no transitive dependencies.']]]]],
        ]);

        $response = $this->postJson(route('workspaces.notes.ask-ai', $this->workspace), [
            'note_id' => $note->id,
            'selection' => 'Third normal form requires no transitive dependencies.',
            'question' => 'What does transitive dependency mean simply?',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonStructure(['status', 'answer']);

        $this->assertNotEmpty($response->json('answer'));
    }
}
