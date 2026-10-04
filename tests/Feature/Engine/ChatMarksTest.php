<?php

namespace Tests\Feature\Engine;

use App\Engine\ChatMarks;
use Tests\TestCase;

/** The tutor's marks as the chat shows them: labelled quotes instead of tags. */
class ChatMarksTest extends TestCase
{
    public function test_each_kind_of_mark_becomes_a_labelled_quote_and_the_rest_stays(): void
    {
        $reply = "Good. Two things to keep:\n\n<finding topic=\"Joins\">A left join keeps every row of the left table.</finding>\n<flashcard topic=\"Joins\"><front>What does a left join keep?</front><back>Every left row.</back></flashcard>\n<attempt topic=\"Joins\" result=\"right\" form=\"recall\"><asked>What is kept?</asked><answer>All left rows</answer></attempt>\n<status topic=\"Joins\" proposed=\"understood\">You explained it twice.</status>\n<checkpoint>Next: outer joins.</checkpoint>\n<summary>We did inner and left joins.</summary>\n\nAge<18 stays as it is.";

        $shown = ChatMarks::present($reply);

        $this->assertStringContainsString("> **Key point · Joins**\n> A left join keeps every row of the left table.", $shown);
        $this->assertStringContainsString("> **Flashcard · Joins**\n> **Front:** What does a left join keep?\n> **Back:** Every left row.", $shown);
        $this->assertStringContainsString("> **Your answer · Joins**\n> **Asked:** What is kept?\n> **You said:** All left rows\n> **Result:** right", $shown);
        $this->assertStringContainsString("> **Status · Joins**\n> Proposed: **understood**\n> You explained it twice.", $shown);
        $this->assertStringContainsString("> **Where we are**\n> Next: outer joins.", $shown);
        $this->assertStringContainsString("> **Summary**\n> We did inner and left joins.", $shown);
        $this->assertStringStartsWith('Good. Two things to keep:', $shown);
        $this->assertStringContainsString('Age<18 stays as it is.', $shown);
        $this->assertStringNotContainsString('<finding', $shown);
        $this->assertStringNotContainsString('</flashcard>', $shown);
    }

    public function test_a_reply_without_marks_is_untouched_and_an_empty_mark_disappears(): void
    {
        $this->assertSame("Just words.\n\n- a list", ChatMarks::present("Just words.\n\n- a list"));
        $this->assertSame('Before  after', ChatMarks::present('Before <finding topic="x">   </finding> after'));
    }

    public function test_an_answer_shows_what_was_right_and_what_to_fix(): void
    {
        $shown = ChatMarks::present('<attempt topic="Joins" result="partial"><asked>What does a left join keep?</asked><answer>The matches</answer><right>You know it keeps the matches.</right><fix>It keeps every left row, matched or not.</fix></attempt>');

        $this->assertStringContainsString("> **Result:** partly right\n> **What was right:** You know it keeps the matches.\n> **To fix:** It keeps every left row, matched or not.", $shown);
        $this->assertStringNotContainsString('<right>', $shown);
    }
}
