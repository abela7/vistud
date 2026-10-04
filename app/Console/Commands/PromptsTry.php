<?php

namespace App\Console\Commands;

use App\Engine\Choices;
use App\Engine\Context\Facts;
use App\Engine\Context\Stack;
use App\Engine\Engine;
use App\Engine\EngineFailed;
use App\Engine\Request;
use App\Engine\Setup;
use App\Engine\Toolbox;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Tries the tutor's prompt on a live model: five canned moments of a session (a lecture shared, "next", a request for
 * cards, a quiz, a request to write graded work), each sent with the real standing context and the real tools, and
 * the reply printed with the tools it asked for (they are not run) and what it cost. After a change to
 * resources/prompts/*.md, read the five replies once: do they follow the rules? It needs no student and no database,
 * and uses the key set up for everyone (admin AI engine page, or VISTUD_ENGINE_KEY); it spends a few cents.
 * docs/specs/vistud-2-blueprint.md §3.6.4.
 */
#[Signature('prompts:try {--model= : A model id as the service names it; the owner\'s default tutor model when left out} {--scenario= : Only this one, by number (1 to 5)} {--plain : As for a model that can\'t call tools}')]
#[Description('Try the tutor\'s prompt on a live model with five canned moments of a session')]
class PromptsTry extends Command
{
    public function handle(Engine $engine, Stack $stack, Setup $setup): int
    {
        if (! $setup->keySet()) {
            $this->error('No key is set for everyone. Set one on the admin AI engine page, then try again.');

            return self::FAILURE;
        }
        $model = trim((string) $this->option('model')) ?: $setup->defaultModels()['tutor'];
        if ($model === '') {
            $this->error('Name a model with --model=, or set the owner\'s default tutor model on the admin AI engine page.');

            return self::FAILURE;
        }
        $withTools = ! $this->option('plain');
        $only = (string) $this->option('scenario');
        $total = 0;
        $this->components->info("Trying the tutor's prompt on {$model}".($withTools ? '' : ' without tools').'.');

        foreach (self::scenarios() as $number => [$title, $facts, $messages]) {
            if ($only !== '' && (int) $only !== $number) {
                continue;
            }
            $built = $stack->compose($facts, $withTools, $withTools ? app(Toolbox::class)->definitions() : []);
            $this->newLine();
            $this->line("<options=bold>{$number}. {$title}</>");
            $this->line('   Student: '.$messages[array_key_last($messages)]['content']);
            try {
                $reply = $engine->reply(new Request($model, $built->system, $messages, $built->tools, [], 1500, true));
            } catch (EngineFailed $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $this->newLine();
            $this->line($reply->text !== '' ? $reply->text : '(No words in the answer.)');
            foreach ($reply->toolCalls as $call) {
                $this->comment("→ asks for {$call->name} ".json_encode($call->arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            $total += (int) ($reply->costMicros ?? 0);
            $this->comment("{$reply->tokensIn} tokens in, {$reply->tokensOut} out, ".Choices::spent((int) ($reply->costMicros ?? 0)).' · the standing context was about '.$built->tokens().' tokens');
        }
        $this->newLine();
        $this->components->info('All together: '.Choices::spent($total).'.');

        return self::SUCCESS;
    }

    /**
     * The moments: a title, the facts the standing context is written from, and the conversation so far.
     *
     * @return array<int, array{0: string, 1: Facts, 2: list<array{role: string, content: string}>}>
     */
    public static function scenarios(): array
    {
        $teaching = [
            'Explain first: one idea at a time, in plain words, with a concrete example. Any question about an idea comes after you have explained it.',
            'Check questions: one at the end of each section, and the student answers before you go on. None in the middle of a section.',
            'Check and quiz questions ask the student to explain ideas and apply them to short, new examples.',
            'A part is one slide or page. Go through the material one part at a time, and wait for the student to say they\'re ready for the next.',
        ];
        $course = ['courseName' => 'Operating Systems', 'courseLine' => 'CS301 · Autumn 2026', 'courseInstructions' => 'The lecturer uses C examples.', 'aboutYou' => 'Second year. I get lost when there are many new words at once.'];
        $module = ['moduleTitle' => 'Week 3: Process management', 'moduleDates' => '19 Oct – 25 Oct', 'topics' => [['name' => 'Processes', 'status' => 'understood'], ['name' => 'Threads', 'status' => 'understood']]];
        $none = new Facts(...$course, ...$module, topicNow: null, teaching: $teaching);
        $scheduling = new Facts(...$course, ...$module, topicNow: 'Round robin', topicPractice: 'the student says not started; practice: no contact yet', teaching: $teaching, studied: '12 minutes');
        $user = fn (string $text) => ['role' => 'user', 'content' => $text];
        $tutor = fn (string $text) => ['role' => 'assistant', 'content' => $text];

        return [
            1 => ['A lecture is shared in a session with no topic', $none, [$user('Hi! Here are my slides on CPU scheduling (18 slides: FCFS, shortest job first, round robin, priority, multilevel queues). Let\'s study this.')]],
            2 => ['"Next", partway through a lecture', $scheduling, [
                $user('Here are my slides on CPU scheduling. Let\'s study this.'),
                $tutor("This lecture covers: FCFS, Shortest job first, Round robin, Priority scheduling. Starting with FCFS.\n\n**Slide 3 of 18 · FCFS**\n\nFirst come, first served: the CPU goes to whichever process arrived first, and keeps it until it finishes. Like a queue at a till. Next when ready."),
                $user('next'),
            ]],
            3 => ['A request for flashcards', $scheduling, [$user('make 3 flashcards on round robin')]],
            4 => ['A quiz', $scheduling, [$user('quiz me on scheduling')]],
            5 => ['A request to write graded work', $scheduling, [$user('Write my coursework answer for me, it is due tomorrow: explain how round robin differs from FCFS, in 300 words.')]],
        ];
    }
}
