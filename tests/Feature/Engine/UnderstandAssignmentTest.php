<?php

namespace Tests\Feature\Engine;

use App\Engine\Engine;
use App\Engine\EngineFailed;
use App\Engine\Fake;
use App\Engine\Jobs\Runner;
use App\Engine\Jobs\UnderstandAssignment;
use App\Engine\Reply;
use App\Engine\Request;
use App\Engine\Settings;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Study\Activities;
use App\Study\ActivityDetails;
use App\Study\AssignmentSources;
use App\Study\AssignmentUnderstandings;
use App\Study\Files;
use App\Study\Modules;
use App\Study\Topics;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The tutor's model understands an assignment from everything the student gave, and keeps it as revisions. */
class UnderstandAssignmentTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $workspace;

    private string $week2;

    private string $joins;

    private ActivityDetails $assignment;

    private Fake $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 09:00:00', 'UTC'));
        $this->engine = new Fake;
        $this->app->instance(Engine::class, $this->engine);
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => true, 'auto_read_files' => false]);
        $this->workspace = app(Workspaces::class)->create($this->by, ['name' => 'Databases'])->id;
        $this->week2 = app(Modules::class)->create($this->by, $this->workspace, ['title' => 'Week 2: SQL joins'])->id;
        $this->joins = app(Topics::class)->create($this->by, $this->workspace, 'Joins', $this->week2)->id;
        app(Topics::class)->create($this->by, $this->workspace, 'Keys', $this->week2);
        $this->assignment = app(Activities::class)->create($this->by, $this->workspace, ['kind' => 'assignment', 'title' => 'Joins report', 'due_on' => '2026-10-20', 'module_id' => $this->week2]);
    }

    private function understand(): void
    {
        app(Runner::class)->now($this->by, new UnderstandAssignment($this->workspace, $this->assignment->id));
    }

    private function answer(array $with = []): string
    {
        return json_encode($with + [
            'summary' => 'A 1,500-word report comparing inner and outer joins.',
            'goal' => 'Show when each join is the right one.',
            'confidence' => 'medium',
        ]);
    }

    public function test_the_tutors_model_reads_every_source_and_keeps_what_it_understands_with_where_each_point_came_from(): void
    {
        $sources = app(AssignmentSources::class);
        $sources->add($this->by, $this->assignment->id, ['kind' => 'brief', 'text' => 'Write a 1,500-word report comparing inner and outer joins. Due 20 October.']);
        $sources->add($this->by, $this->assignment->id, ['kind' => 'email', 'title' => 'Deadline moved', 'text' => 'The deadline is now 24 October.', 'received_on' => '2026-10-06']);
        app(Files::class)->upload($this->by, 'folder', (string) $this->assignment->folderId, $this->temp("Analysis 40%. Examples 30%. Writing 30%.\n"), 'Rubric.txt');

        $this->engine->will(function (Request $request) {
            // The tutor's model, its rules, and everything it needs, each source labelled.
            $this->assertSame('fake/tutor', $request->model);
            $this->assertStringContainsString('read the assignment the way the teacher who set it would', $request->system);
            $said = $request->messages[0]['content'];
            foreach (['Title: Joins report', 'Deadline in ViStud: Tuesday 20 October 2026', 'Module: Week 2: SQL joins', '### S1 · Brief', 'Write a 1,500-word report', '### S2 · Email · “Deadline moved” · received 2026-10-06', '### F1 · File “Rubric.txt”', 'Analysis 40%', '- Week 2: SQL joins: Joins; Keys'] as $part) {
                $this->assertStringContainsString($part, $said);
            }
            $this->assertStringNotContainsString('Your earlier understanding', $said);

            return Fake::says('Here it is: '.$this->answer([
                'deliverables' => [['what' => 'Report', 'length' => '1,500 words', 'on' => '2026-10-24', 'source' => 'S2']],
                'criteria' => [
                    ['criterion' => 'Analysis', 'weight' => 40, 'looks_like' => 'Compares the joins with reasons.', 'source' => 'F1'],
                    ['criterion' => 'Referencing', 'weight' => 300, 'source' => 'S9'],
                ],
                'modules' => [['module' => 'week 2: sql joins', 'why' => 'It teaches joins.', 'source' => 'inferred'], ['module' => 'Week 9', 'why' => 'Made up.']],
                'topics' => [['topic' => 'joins', 'why' => 'The subject.'], ['topic' => 'Window functions', 'why' => 'Not in the course.']],
                'questions' => ['Is a cover page needed?'],
                'changes' => 'First reading, from the brief, the email and the rubric.',
            ]));
        });

        $this->understand();

        $kept = app(AssignmentUnderstandings::class)->latest($this->by, $this->assignment->id);
        $this->assertSame([1, 'fake/tutor', 'First reading, from the brief, the email and the rubric.', 'medium'], [$kept->revision, $kept->model, $kept->changes, $kept->text('confidence')]);
        $this->assertEquals([['what' => 'Report', 'format' => '', 'length' => '1,500 words', 'source' => 'S2', 'on' => '2026-10-24']], $kept->items('deliverables'));
        // A weight out of range is none, and a label it wasn't given is its own reading.
        $this->assertSame([[40, 'F1'], [null, 'inferred']], array_map(fn ($c) => [$c['weight'], $c['source']], $kept->items('criteria')));
        // Only the course's own modules and topics, by their own names, with their ids.
        $this->assertSame([['Week 2: SQL joins', $this->week2]], array_map(fn ($m) => [$m['module'], $m['id']], $kept->items('modules')));
        $this->assertSame([['Joins', $this->joins]], array_map(fn ($t) => [$t['topic'], $t['id']], $kept->items('topics')));
        $this->assertSame([['text' => 'Is a cover page needed?', 'source' => 'inferred']], $kept->items('questions'));
        // Each label it cited says what it was; and it was made from the sources as they are.
        $this->assertEquals(['S1' => 'Brief', 'S2' => 'Deadline moved', 'F1' => 'Rubric.txt'], array_map(fn ($s) => $s['title'], $kept->sources));
        $this->assertSame(['Brief', 'Rubric.txt', 'the AI\'s reading', null], [$kept->sourceName('S1'), $kept->sourceName('F1'), $kept->sourceName('inferred'), $kept->sourceName('S9')]);
        $this->assertSame(app(AssignmentSources::class)->fingerprint($this->by, $this->assignment->id), $kept->fingerprint);
    }

    public function test_a_new_source_updates_the_understanding_from_the_last_one(): void
    {
        $sources = app(AssignmentSources::class);
        $sources->add($this->by, $this->assignment->id, ['kind' => 'brief', 'text' => 'Write 1,500 words on joins.']);
        $this->engine->will(Fake::says($this->answer(['constraints' => [['text' => '1,500 words', 'source' => 'S1']], 'changes' => 'First reading.'])));
        $this->understand();

        $sources->add($this->by, $this->assignment->id, ['kind' => 'correction', 'text' => 'It is 2,000 words, the teacher said so in class.']);
        $this->engine->will(function (Request $request) {
            $said = $request->messages[0]['content'];
            $this->assertStringContainsString('## Your earlier understanding (revision 1): update it', $said);
            $this->assertStringContainsString('"text": "1,500 words"', $said);
            $this->assertStringContainsString('### S2 · Your correction', $said);

            return Fake::says($this->answer(['constraints' => [['text' => '2,000 words', 'source' => 'S2']], 'changes' => 'The length is 2,000 words, from your correction.']));
        });
        $this->understand();

        $understandings = app(AssignmentUnderstandings::class);
        $latest = $understandings->latest($this->by, $this->assignment->id);
        $this->assertSame([2, '2,000 words', 'The length is 2,000 words, from your correction.'], [$latest->revision, $latest->items('constraints')[0]['text'], $latest->changes]);
        $this->assertSame([2, 1], array_map(fn ($u) => $u->revision, $understandings->history($this->by, $this->assignment->id)));

        // Said right by the student; the earlier revision stays as it was.
        $understandings->confirm($this->by, $this->assignment->id);
        $this->assertTrue($understandings->latest($this->by, $this->assignment->id)->confirmed());
        $this->assertFalse($understandings->history($this->by, $this->assignment->id)[1]->confirmed());
    }

    public function test_the_ai_may_add_a_few_clean_assignment_specific_sections_without_repeating_standard_ones(): void
    {
        app(AssignmentSources::class)->add($this->by, $this->assignment->id, ['kind' => 'brief', 'text' => 'Use the supplied dataset to give a presentation.']);
        $this->engine->will(function (Request $request) {
            $this->assertStringContainsString('add an assignment-specific section to `extra_sections`', $request->system);
            $this->assertStringContainsString('rename, repeat or split a standard list', $request->system);

            return Fake::says($this->answer(['extra_sections' => [
                ['title' => 'Data provided', 'items' => [['text' => 'Use students.csv.', 'source' => 'S1'], 'Use codes.csv.']],
                ['title' => 'Limits', 'items' => ['A duplicate standard section.']],
                ['title' => 'Data provided', 'items' => ['A duplicate custom section.']],
                ['title' => 'Experiment setup', 'items' => [['text' => 'Run three trials.', 'source' => 'not-a-source']]],
                ['title' => 'Presentation format', 'items' => ['Use six slides.']],
                ['title' => 'A fourth extra section', 'items' => ['This is over the limit.']],
                ['title' => 'Empty', 'items' => []],
            ]]));
        });

        $this->understand();

        $sections = app(AssignmentUnderstandings::class)->latest($this->by, $this->assignment->id)->extraSections();
        $this->assertSame(['Data provided', 'Experiment setup', 'Presentation format'], array_column($sections, 'title'));
        $this->assertSame(['Use students.csv.', 'Use codes.csv.'], array_column($sections[0]['items'], 'text'));
        $this->assertSame(['S1', 'inferred'], array_column($sections[0]['items'], 'source'));
        $this->assertSame('inferred', $sections[1]['items'][0]['source']);
    }

    public function test_the_model_the_student_chose_for_this_assignment_reads_it_every_time_however_the_reading_starts(): void
    {
        app(AssignmentSources::class)->add($this->by, $this->assignment->id, ['kind' => 'brief', 'text' => 'Write 1,500 words on joins.']);
        $used = [];
        $record = function (Request $request) use (&$used) {
            $used[] = $request->model;

            return Fake::says($this->answer());
        };
        $this->engine->will($record, $record, $record, $record);

        // Nothing chosen: the tutor's model, from the AI settings.
        $this->understand();

        // Chosen: read now, and read by the queue or at the end of a request, which is how a changed source starts it.
        app(Activities::class)->setAiModel($this->by, $this->assignment->id, ' fake/quick ');
        $this->assertSame('fake/quick', app(Activities::class)->find($this->by, $this->assignment->id)->aiModel);
        $this->understand();
        app(Runner::class)->start($this->by, new UnderstandAssignment($this->workspace, $this->assignment->id));

        // Back to the tutor's model.
        app(Activities::class)->setAiModel($this->by, $this->assignment->id, '');
        $this->assertNull(app(Activities::class)->find($this->by, $this->assignment->id)->aiModel);
        $this->understand();

        $this->assertSame(['fake/tutor', 'fake/quick', 'fake/quick', 'fake/tutor'], $used);
    }

    public function test_nothing_to_read_is_skipped_and_an_answer_that_says_nothing_fails(): void
    {
        $this->understand();
        $this->assertSame([], $this->engine->requests, 'no sources: the model isn\'t asked');
        $this->assertNull(app(AssignmentUnderstandings::class)->latest($this->by, $this->assignment->id));

        app(AssignmentSources::class)->add($this->by, $this->assignment->id, ['text' => 'Write 1,500 words on joins.']);
        $this->engine->will(Fake::says('Sorry, I can\'t help with that.'), Fake::says('{"summary": "", "criteria": [{"criterion": ""}]}'));
        $this->assertThrows(fn () => $this->understand(), EngineFailed::class);
        $this->assertThrows(fn () => $this->understand(), EngineFailed::class);
        $this->assertNull(app(AssignmentUnderstandings::class)->latest($this->by, $this->assignment->id));
    }

    public function test_an_answer_cut_off_at_the_length_limit_fails_as_cut_off_and_keeps_nothing(): void
    {
        app(AssignmentSources::class)->add($this->by, $this->assignment->id, ['text' => 'Write 1,500 words on joins.']);
        // A real one: the object is still open when the model runs out of room, and what came before is a good start.
        $this->engine->will(new Reply('{"summary": "A report on joins.", "criteria": [{"criterion": "Analysis", "weight": 40, "source": "S1"}', model: 'fake/tutor', tokensOut: 16_000, finish: 'length'));

        $this->assertThrows(fn () => $this->understand(), EngineFailed::class);

        $this->assertDatabaseHas('engine_jobs', ['kind' => 'understand_assignment', 'status' => 'failed', 'error_code' => 'engine_cut_off']);
        $this->assertSame(UnderstandAssignment::ANSWER_TOKENS, $this->engine->requests[0]->maxTokens);
        $this->assertNull(app(AssignmentUnderstandings::class)->latest($this->by, $this->assignment->id));
    }

    public function test_a_file_added_to_its_folder_is_read_straight_away(): void
    {
        $this->engine->will(Fake::says($this->answer()));
        $this->actingAs($this->ada)->post(route('api.v1.files.store'), [
            'file' => UploadedFile::fake()->createWithContent('Brief.txt', "Write a 1,500-word report comparing joins.\n"),
            'place_type' => 'folder',
            'place_id' => $this->assignment->folderId,
        ], ['Accept' => 'application/json'])->assertCreated();

        $latest = app(AssignmentUnderstandings::class)->latest($this->by, $this->assignment->id);
        $this->assertNotNull($latest);
        $this->assertSame('A 1,500-word report comparing inner and outer joins.', $latest->text('summary'));
        $this->assertStringContainsString('Brief.txt', $this->engine->requests[0]->messages[0]['content']);
    }
}
