<?php

namespace Tests\Feature\Engine;

use App\Engine\ChatBlocks;
use App\Engine\Context\Stack;
use App\Engine\Context\Tokens;
use App\Engine\CourseGuide;
use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Settings;
use App\Engine\Skills\Skills;
use App\Engine\VistudAi;
use App\Livewire\Workspaces\TutorChat;
use App\Mcp\Plugins\PluginBuilder;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * The UI skill (resources/skills/ui/SKILL.md): the model writes small tags, ViStud draws them from its own components;
 * nothing the model writes becomes HTML, and a block that isn't exactly right shows as plain words.
 */
class ChatBlocksTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    public function test_each_block_is_drawn_from_vistuds_own_components_with_markdown_inside(): void
    {
        $reply = "Here it is.\n\n<card title=\"Left join\">Keeps **every** row of the left table.</card>\n\n<copy>SELECT * FROM a LEFT JOIN b ON a.id = b.a_id;</copy>\n\n<choices>\nA left join\nAn inner join\nI'm not sure\n</choices>\n\n<steps>\nName the tables\nWrite the `ON` condition\n</steps>\n\n<compare left=\"Inner\" right=\"Left\">\nMatched rows only | Every left row\nFast | Slower\n</compare>\n\nThen we go on.";

        $html = ChatBlocks::html($reply);

        $this->assertStringContainsString('<p>Here it is.</p>', $html);
        $this->assertStringContainsString('<section class="chat-card"><h4 class="chat-card-title">Left join</h4><div class="chat-card-body"><p>Keeps <strong>every</strong> row of the left table.</p>', $html);
        $this->assertStringContainsString('<div class="chat-copy-text">SELECT * FROM a LEFT JOIN b ON a.id = b.a_id;</div>', $html);
        $this->assertStringContainsString('data-chat-copy', $html);
        $this->assertStringContainsString('<button type="button" class="chat-chip chat-choice" data-chat-choice="A left join">A left join</button>', $html);
        $this->assertStringContainsString('data-chat-choice="I&#039;m not sure">I&#039;m not sure</button>', $html);
        $this->assertStringContainsString('<li class="chat-step"><label class="chat-step-label"><input type="checkbox" class="chat-step-box"><span class="chat-step-text">Write the <code>ON</code> condition</span></label></li>', $html);
        $this->assertStringContainsString('<table class="chat-compare"><thead><tr><th>Inner</th><th>Left</th></tr></thead><tbody><tr><td>Matched rows only</td><td>Every left row</td></tr><tr><td>Fast</td><td>Slower</td></tr></tbody></table>', $html);
        $this->assertStringContainsString('<p>Then we go on.</p>', $html);
        $this->assertStringNotContainsString('vsblk', $html);
        foreach (ChatBlocks::KINDS as $kind) {
            $this->assertStringNotContainsString("<{$kind}", $html);
        }
    }

    public function test_nothing_the_model_writes_becomes_html_and_a_tricked_tag_shows_as_words(): void
    {
        // HTML inside a block is stripped, as everywhere in a reply.
        $html = ChatBlocks::html('<card title="x">Hi <script>alert(1)</script> <img src=x onerror="alert(1)"> **there**</card>');
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringContainsString('<strong>there</strong>', $html);

        // An attribute that isn't the block's, or a value with a tag in it: plain words, no attribute kept.
        $html = ChatBlocks::html('<card title="x" onclick="steal()">Words</card>');
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('chat-card', $html);
        $this->assertStringContainsString('Words', $html);
        $html = ChatBlocks::html('<card title="a<b">Words</card>');
        $this->assertStringNotContainsString('chat-card', $html);
        $this->assertStringContainsString('Words', $html);
        // A title is escaped.
        $this->assertStringContainsString('<h4 class="chat-card-title">Tom &amp; Jerry &quot;x&quot;</h4>', ChatBlocks::html('<card title="Tom & Jerry &quot;x&quot;">Words</card>'));

        // A choice with a tag character in it isn't a choice; the words sent are exactly the words shown.
        $html = ChatBlocks::html("<choices>\nFine\n<b>Bold</b>\n</choices>");
        $this->assertStringNotContainsString('chat-choice', $html);
        $this->assertStringContainsString('Fine', $html);
    }

    public function test_a_block_that_isnt_exactly_right_shows_as_plain_words_with_its_tags_gone(): void
    {
        $cases = [
            'unclosed' => '<card title="x">Words without an end',
            'nested' => '<card title="x">Words <copy>inside</copy> here</card>',
            'one choice' => "<choices>\nOnly one\n</choices>",
            'seven choices' => "<choices>\n1\n2\n3\n4\n5\n6\n7\n</choices>",
            'compare without sides' => "<compare>\na | b\n</compare>",
            'compare row without a bar' => "<compare left=\"A\" right=\"B\">\nno bar here\n</compare>",
            'empty' => '<copy>   </copy>',
            'too long' => '<copy>'.str_repeat('x', 2001).'</copy>',
        ];
        foreach ($cases as $name => $reply) {
            $html = ChatBlocks::html($reply);
            foreach (['chat-card', 'chat-copy', 'chat-choice', 'chat-steps', 'chat-compare', '<card', '<copy', '<choices', '<compare'] as $sign) {
                $this->assertStringNotContainsString($sign, $html, $name);
            }
        }
        $this->assertStringContainsString('Words without an end', ChatBlocks::html($cases['unclosed']));
        $this->assertStringContainsString('inside', ChatBlocks::html($cases['nested']));

        // Past the most, the rest are words.
        $many = str_repeat("<copy>one</copy>\n\n", ChatBlocks::MOST + 2);
        $this->assertSame(ChatBlocks::MOST, substr_count(ChatBlocks::html($many), 'class="chat-copy"'));

        // Age<18 and other angle brackets stay as they are.
        $this->assertStringContainsString('Age&lt;18', ChatBlocks::html('Age<18 stays.'));
    }

    public function test_a_tag_inside_code_stays_code_and_a_half_written_block_is_held_back(): void
    {
        $html = ChatBlocks::html("Write it like this:\n\n```\n<card title=\"x\">Words</card>\n```\n\nand inline `<copy>` too.");
        $this->assertStringContainsString('&lt;card title=&quot;x&quot;&gt;Words&lt;/card&gt;', $html);
        $this->assertStringContainsString('<code>&lt;copy&gt;</code>', $html);
        $this->assertStringNotContainsString('chat-card', $html);

        $this->assertSame('So far ', ChatBlocks::partial('So far <card title="x">half writ'));
        $this->assertSame('So far ', ChatBlocks::partial('So far <car'));
        $this->assertSame('So far <copy>done</copy> and', ChatBlocks::partial('So far <copy>done</copy> and'));
    }

    public function test_every_vistud_chat_carries_the_skill_and_the_outside_apps_do_not(): void
    {
        $skill = Skills::read('ui');
        $this->assertSame(['ui', Skills::VISTUD], [$skill['name'], $skill['where']]);
        $this->assertStringStartsWith('## Showing things in the chat', $skill['body']);
        $this->assertSame($skill['body'], Skills::alwaysOn());

        $this->assertStringEndsWith($skill['body'], Stack::rules(true));
        $this->assertStringEndsWith($skill['body'], Stack::rules(false));
        // Not the quick helper or the course guide, whose short talks have no room for it.
        $this->assertStringNotContainsString('Showing things in the chat', Stack::helperRules());
        $this->assertStringNotContainsString('Showing things in the chat', CourseGuide::rules());
        // An always-on skill stays small: it is in every call of every chat.
        $this->assertLessThanOrEqual(Skills::ALWAYS_ON_TOKENS, Tokens::of(Skills::alwaysOn()));

        $by = $this->principal($this->student());
        $this->assertStringContainsString($skill['body'], app(VistudAi::class)->system($by));

        // The AI apps students connect get the other skills, never this one: they can't draw it.
        $packaged = array_keys(app(PluginBuilder::class)->skills());
        $this->assertNotContains('ui', $packaged);
        $this->assertContains('quiz-me', $packaged);
    }

    public function test_the_chat_draws_the_blocks_and_a_tapped_choice_is_sent_as_the_students_words(): void
    {
        $this->withoutVite();
        $this->app->instance(Engine::class, $engine = new Fake);
        $user = $this->student();
        $this->actingAs($user);
        $by = $this->principal($user);
        $this->ownAiKey($by);
        app(Settings::class)->set($by, ['tutor_model' => 'fake/tutor', 'consent' => true]);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        $engine->will(Fake::says("Which join?\n\n<choices>\nA left join\nAn inner join\n</choices>", 100, 'fake/tutor'), Fake::says('Right.', 100, 'fake/tutor'));
        $ai = app(VistudAi::class);
        $id = $ai->start($by, 'Help me with joins');
        $ai->send($by, $id, 'Help me with joins');

        $chat = Livewire::test(TutorChat::class, ['vistud' => true, 'conversationId' => $id])
            ->assertSee('data-chat-choice="A left join"', false)->assertSee('chat-choice', false)->assertDontSee('<choices', false);
        $chat->call('send', 'A left join', []);
        $sent = $engine->last()->messages;
        $this->assertSame(['role' => 'user', 'content' => 'A left join'], $sent[array_key_last($sent)]);
    }
}
