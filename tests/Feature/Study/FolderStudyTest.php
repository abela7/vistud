<?php

namespace Tests\Feature\Study;

use App\Models\User;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Study\Files;
use App\Study\Findings;
use App\Study\Flashcards;
use App\Study\Folders;
use App\Study\ModuleBriefs;
use App\Study\Modules;
use App\Study\Questions;
use App\Study\Quizzes;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\TopicSuggestions;
use App\Study\Workspaces;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * Studying by folder (docs/specs/vistud-2-blueprint.md, Phase 9): a folder in a module is a place to study, and what is
 * made there (topics, questions, cards, quizzes, the topics the reader finds in its files) belongs to it, beside its
 * module. Moving a folder takes it along; deleting one lifts it to where the folder was.
 */
class FolderStudyTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $workspace;

    private string $week1;

    private string $week2;

    /** Week 1's two folders, as Abel keeps them: a lecture with its lab. */
    private string $first;

    private string $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->workspace = app(Workspaces::class)->create($this->by, ['name' => 'Operating Systems'])->id;
        $this->week1 = app(Modules::class)->create($this->by, $this->workspace, ['title' => 'Week 1: OS Structure | Processes & Threads'])->id;
        $this->week2 = app(Modules::class)->create($this->by, $this->workspace, ['title' => 'Week 2'])->id;
        $this->first = app(Folders::class)->create($this->by, 'module', $this->week1, 'Lecture 1 + Lab 1')->id;
        $this->second = app(Folders::class)->create($this->by, 'module', $this->week1, 'Lecture 2 + Lab 2')->id;
    }

    private function row(string $table, string $id): object
    {
        return DB::table($table)->where('id', $id)->first();
    }

    public function test_a_session_on_a_folder_is_in_its_module_and_studies_the_whole_folder(): void
    {
        $session = app(Sessions::class)->start($this->by, $this->workspace, folderId: $this->first);

        $this->assertSame([$this->first, $this->week1, 'module', null], [$session->folderId, $session->moduleId, $session->mode, $session->topicId]);
        $this->assertSame([$session->id], array_map(fn ($s) => $s->id, app(Sessions::class)->forFolder($this->by, $this->workspace, [$this->first])));
        $this->assertSame([], app(Sessions::class)->forFolder($this->by, $this->workspace, [$this->second]));
        // The module's sessions have it too.
        $this->assertSame([$session->id], array_map(fn ($s) => $s->id, app(Sessions::class)->forModule($this->by, $this->workspace, $this->week1)));
    }

    public function test_a_folder_of_another_module_or_another_student_is_refused(): void
    {
        $this->assertThrows(fn () => app(Sessions::class)->start($this->by, $this->workspace, moduleId: $this->week2, folderId: $this->first), NotFound::class);
        $bob = $this->student();
        $theirs = app(Workspaces::class)->create($this->principal($bob), ['name' => 'Private'])->id;
        $theirModule = app(Modules::class)->create($this->principal($bob), $theirs, ['title' => 'Theirs'])->id;
        $theirFolder = app(Folders::class)->create($this->principal($bob), 'module', $theirModule, 'Secret')->id;
        $this->assertThrows(fn () => app(Sessions::class)->start($this->by, $this->workspace, folderId: $theirFolder), NotFound::class);
        $this->assertNull(app(Sessions::class)->current($this->by));
    }

    public function test_studying_a_folders_topic_is_studying_in_the_folder_and_switching_topic_keeps_the_folder(): void
    {
        $processes = app(Topics::class)->create($this->by, $this->workspace, 'Processes', folderId: $this->first);
        $memory = app(Topics::class)->create($this->by, $this->workspace, 'Memory', $this->week2);

        $session = app(Sessions::class)->start($this->by, $this->workspace, $processes->id);
        $this->assertSame([$this->first, $this->week1, 'topic'], [$session->folderId, $session->moduleId, $session->mode]);

        $switched = app(Sessions::class)->setTopic($this->by, $session->id, $memory->id);
        $this->assertSame([$memory->id, $this->first, $this->week1], [$switched->topicId, $switched->folderId, $switched->moduleId]);
    }

    public function test_a_topic_made_in_a_folder_is_in_its_module_and_moving_it_takes_its_cards_and_questions(): void
    {
        $topics = app(Topics::class);
        $threads = $topics->create($this->by, $this->workspace, 'Threads', $this->week2, $this->first);
        $this->assertSame([$this->week1, $this->first], [$threads->moduleId, $threads->folderId]);
        $this->assertTrue($threads->in(app(Folders::class)->within($this->by, $this->first)));

        $card = app(Flashcards::class)->add($this->by, $this->workspace, $threads->id, 'What is a thread?', 'A path of execution.');
        $question = app(Questions::class)->ask($this->by, $this->workspace, 'Why threads?', $threads->id);
        $this->assertSame([$this->first, $this->first], [$this->row('flashcards', $card)->folder_id, $question->folderId]);

        $topics->move($this->by, $threads->id, null, $this->second);
        $this->assertSame([$this->week1, $this->second], [$topics->find($this->by, $threads->id)->moduleId, $topics->find($this->by, $threads->id)->folderId]);
        $this->assertSame($this->second, $this->row('flashcards', $card)->folder_id);
        $this->assertSame($this->second, $this->row('questions', $question->id)->folder_id);

        $topics->move($this->by, $threads->id, $this->week2);
        $this->assertSame([$this->week2, null], [$this->row('flashcards', $card)->module_id, $this->row('flashcards', $card)->folder_id]);
    }

    public function test_cards_questions_and_quizzes_made_in_a_folders_session_without_a_topic_are_the_folders(): void
    {
        $session = app(Sessions::class)->start($this->by, $this->workspace, folderId: $this->first);

        $card = app(Flashcards::class)->add($this->by, $this->workspace, null, 'What does a kernel do?', 'Manages the hardware.', 'ai', $session->id);
        $question = app(Questions::class)->ask($this->by, $this->workspace, 'What is a system call?');
        $this->assertSame([$this->week1, $this->first], [$this->row('flashcards', $card)->module_id, $this->row('flashcards', $card)->folder_id]);
        $this->assertSame([$this->week1, $this->first, $session->id], [$question->moduleId, $question->folderId, $question->sessionId]);

        // Asked about another module, it is not the folder's.
        $elsewhere = app(Questions::class)->ask($this->by, $this->workspace, 'And paging?', null, $this->week2);
        $this->assertSame([$this->week2, null], [$elsewhere->moduleId, $elsewhere->folderId]);

        $quiz = app(Quizzes::class)->record($this->by, $session->id, ['kind' => 'quiz', 'questions' => [['asked' => 'What is a kernel?', 'answer' => 'The core', 'result' => 'correct', 'right' => 'It manages the hardware.', 'fix' => '']]]);
        $this->assertSame($this->first, $this->row('quizzes', $quiz->id)->folder_id);
    }

    public function test_a_card_or_question_can_be_made_in_a_folder_and_lists_narrow_to_a_folder(): void
    {
        $inFirst = app(Flashcards::class)->add($this->by, $this->workspace, null, 'Front', 'Back', folderId: $this->first);
        app(Flashcards::class)->add($this->by, $this->workspace, null, 'Other', 'Card', moduleId: $this->week1);
        $asked = app(Questions::class)->ask($this->by, $this->workspace, 'In the second?', folderId: $this->second);

        $first = app(Folders::class)->within($this->by, $this->first);
        $this->assertSame([$inFirst], array_map(fn ($c) => $c->id, app(Flashcards::class)->list($this->by, $this->workspace, folderIds: $first)));
        $this->assertSame(1, app(Flashcards::class)->counts($this->by, $this->workspace, null, null, $first)['total']);
        $this->assertSame([$inFirst], app(Flashcards::class)->queue($this->by, $this->workspace, folderIds: $first));
        $this->assertSame(2, app(Flashcards::class)->counts($this->by, $this->workspace, null, $this->week1)['total']);
        $this->assertSame([$asked->id], array_map(fn ($q) => $q->id, app(Questions::class)->list($this->by, $this->workspace, folderIds: app(Folders::class)->within($this->by, $this->second))));
        $this->assertSame([], app(Questions::class)->list($this->by, $this->workspace, folderIds: $first));
    }

    public function test_a_folder_holds_the_folders_inside_it_and_knows_its_path(): void
    {
        $labs = app(Folders::class)->create($this->by, 'folder', $this->first, 'Labs')->id;
        $deeper = app(Folders::class)->create($this->by, 'folder', $labs, 'Sheets')->id;

        $this->assertSame([$this->first, $labs, $deeper], app(Folders::class)->within($this->by, $this->first));
        $this->assertSame([$labs, $deeper], app(Folders::class)->within($this->by, $labs));
        $this->assertSame(['Lecture 1 + Lab 1', 'Labs', 'Sheets'], array_map(fn ($f) => $f->name, app(Folders::class)->path($this->by, $deeper)));

        // A card made in a folder inside it is in it too.
        $card = app(Flashcards::class)->add($this->by, $this->workspace, null, 'Front', 'Back', folderId: $deeper);
        $this->assertSame([$card], array_map(fn ($c) => $c->id, app(Flashcards::class)->list($this->by, $this->workspace, folderIds: app(Folders::class)->within($this->by, $this->first))));
    }

    public function test_topics_found_in_a_folders_files_wait_in_the_folder_and_are_added_to_it(): void
    {
        $lecture = app(Files::class)->upload($this->by, 'folder', $this->first, $this->temp("Slides.\n"), 'Lecture 1 - OS Structure.txt');
        $other = app(Files::class)->upload($this->by, 'folder', $this->second, $this->temp("Slides.\n"), 'Lecture 2.txt');
        $suggestions = app(TopicSuggestions::class);
        $suggestions->suggest($this->by, $this->week1, $lecture->id, ['Kernel', 'System calls']);
        $suggestions->suggest($this->by, $this->week1, $other->id, ['Processes']);

        $first = app(Folders::class)->within($this->by, $this->first);
        $this->assertSame(['Kernel', 'System calls'], array_map(fn ($s) => $s->name, $suggestions->list($this->by, $this->week1, $first)));
        $this->assertSame(3, count($suggestions->list($this->by, $this->week1)));

        $this->assertSame(2, $suggestions->addAll($this->by, $this->week1, $first));
        $kernel = collect(app(Topics::class)->list($this->by, $this->workspace))->firstWhere('name', 'Kernel');
        $this->assertSame([$this->week1, $this->first], [$kernel->moduleId, $kernel->folderId]);
        $this->assertSame(['Processes'], array_map(fn ($s) => $s->name, $suggestions->list($this->by, $this->week1)));

        $suggestions->dismissAll($this->by, $this->week1, $first);
        $this->assertCount(1, $suggestions->list($this->by, $this->week1));
        $suggestions->dismissAll($this->by, $this->week1, app(Folders::class)->within($this->by, $this->second));
        $this->assertSame([], $suggestions->list($this->by, $this->week1));
    }

    public function test_a_file_put_in_a_folder_brings_the_topics_found_in_it_that_wait(): void
    {
        // Read before the folders were made: what the reader found waits in the module.
        $lecture = app(Files::class)->upload($this->by, 'module', $this->week1, $this->temp("Slides.\n"), 'Lecture 1 - OS Structure.txt');
        $suggestions = app(TopicSuggestions::class);
        $suggestions->suggest($this->by, $this->week1, $lecture->id, ['Kernel', 'System calls']);
        $suggestions->addAll($this->by, $this->week1, null);
        $suggestions->suggest($this->by, $this->week1, $lecture->id, ['Interrupts']);
        $first = app(Folders::class)->within($this->by, $this->first);
        $this->assertSame([], $suggestions->list($this->by, $this->week1, $first));

        app(Files::class)->move($this->by, $lecture->id, 'folder', $this->first);
        $this->assertSame(['Interrupts'], array_map(fn ($s) => $s->name, $suggestions->list($this->by, $this->week1, $first)));
        // What was added is a topic, which a file does not take along; it is put in a folder on its own.
        $this->assertNull(collect(app(Topics::class)->list($this->by, $this->workspace))->firstWhere('name', 'Kernel')->folderId);

        // Back out of the folder, they wait in the module again; to another module, they stay where they were found.
        app(Files::class)->move($this->by, $lecture->id, 'module', $this->week1);
        $this->assertSame([], $suggestions->list($this->by, $this->week1, $first));
        app(Files::class)->move($this->by, $lecture->id, 'folder', $this->second);
        app(Files::class)->move($this->by, $lecture->id, 'module', $this->week2);
        $this->assertSame([$this->week1, $this->second], [DB::table('topic_suggestions')->where('name', 'Interrupts')->value('module_id'), DB::table('topic_suggestions')->where('name', 'Interrupts')->value('folder_id')]);
    }

    public function test_moving_a_folder_to_another_module_takes_what_was_studied_in_it(): void
    {
        $topic = app(Topics::class)->create($this->by, $this->workspace, 'Kernel', folderId: $this->first);
        $card = app(Flashcards::class)->add($this->by, $this->workspace, $topic->id, 'Front', 'Back');
        $session = app(Sessions::class)->start($this->by, $this->workspace, folderId: $this->first);
        app(Sessions::class)->end($this->by, $session->id);
        $file = app(Files::class)->upload($this->by, 'folder', $this->first, $this->temp("Slides.\n"), 'Lecture 1.txt');
        app(TopicSuggestions::class)->suggest($this->by, $this->week1, $file->id, ['Shells', 'Paging']);
        // Week 2 has been suggested Paging already: the moved one goes.
        app(TopicSuggestions::class)->suggest($this->by, $this->week2, null, ['Paging']);

        app(Folders::class)->move($this->by, $this->first, 'module', $this->week2);

        $this->assertSame([$this->week2, $this->first], [app(Topics::class)->find($this->by, $topic->id)->moduleId, app(Topics::class)->find($this->by, $topic->id)->folderId]);
        $this->assertSame($this->week2, $this->row('flashcards', $card)->module_id);
        $this->assertSame($this->week2, $this->row('study_sessions', $session->id)->module_id);
        $this->assertSame(['Shells', 'Paging'], array_map(fn ($s) => $s->name, app(TopicSuggestions::class)->list($this->by, $this->week2)));
        $this->assertSame([], app(TopicSuggestions::class)->list($this->by, $this->week1));
    }

    public function test_deleting_an_empty_folder_lifts_what_was_studied_in_it_to_where_the_folder_was(): void
    {
        $labs = app(Folders::class)->create($this->by, 'folder', $this->first, 'Labs')->id;
        $inLabs = app(Topics::class)->create($this->by, $this->workspace, 'Shells', folderId: $labs);
        $inFirst = app(Topics::class)->create($this->by, $this->workspace, 'Kernel', folderId: $this->first);
        $card = app(Flashcards::class)->add($this->by, $this->workspace, null, 'Front', 'Back', folderId: $labs);

        app(Folders::class)->delete($this->by, $labs);
        $this->assertSame($this->first, app(Topics::class)->find($this->by, $inLabs->id)->folderId);
        $this->assertSame($this->first, $this->row('flashcards', $card)->folder_id);

        app(Folders::class)->delete($this->by, $this->first);
        $this->assertSame([$this->week1, null], [app(Topics::class)->find($this->by, $inFirst->id)->moduleId, app(Topics::class)->find($this->by, $inFirst->id)->folderId]);
        $this->assertSame([$this->week1, null], [$this->row('flashcards', $card)->module_id, $this->row('flashcards', $card)->folder_id]);
    }

    public function test_a_folders_brief_is_its_own_files_questions_key_points_and_last_stop(): void
    {
        app(Files::class)->upload($this->by, 'folder', $this->first, $this->temp("Slides.\n"), 'Lecture 1 - OS Structure.txt');
        app(Files::class)->upload($this->by, 'folder', $this->second, $this->temp("Slides.\n"), 'Lecture 2.txt');
        app(Files::class)->upload($this->by, 'module', $this->week1, $this->temp("Notes.\n"), 'Week notes.txt');
        $kernel = app(Topics::class)->create($this->by, $this->workspace, 'Kernel', folderId: $this->first);
        app(Topics::class)->create($this->by, $this->workspace, 'Threads', folderId: $this->second);
        app(Findings::class)->add($this->by, $kernel->id, ['text' => 'The kernel runs in privileged mode.']);
        app(Questions::class)->ask($this->by, $this->workspace, 'What is a system call?', folderId: $this->first);
        app(Questions::class)->ask($this->by, $this->workspace, 'What is a thread pool?', folderId: $this->second);
        $session = app(Sessions::class)->start($this->by, $this->workspace, folderId: $this->first);
        app(Sessions::class)->setCheckpoint($this->by, $session->id, 'Stopped at slide 12, before system calls.');

        $brief = app(ModuleBriefs::class)->forFolder($this->by, $this->first);

        $this->assertSame(['Lecture 1 - OS Structure.txt (not read yet)'], $brief->files);
        $this->assertSame(['"What is a system call?"'], $brief->questions);
        $this->assertSame(['The kernel runs in privileged mode.'], $brief->keyPoints);
        $this->assertSame('Stopped at slide 12, before system calls.', $brief->last);
        $this->assertSame(1, DB::table('folder_briefs')->where('folder_id', $this->first)->count());
        // The module's brief still has everything in it.
        $this->assertCount(3, app(ModuleBriefs::class)->for($this->by, $this->week1)->files);
    }
}
