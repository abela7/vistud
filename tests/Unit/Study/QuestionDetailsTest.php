<?php

namespace Tests\Unit\Study;

use App\Study\QuestionDetails;
use PHPUnit\Framework\TestCase;

/** What counts as an open question is one rule, the one the Progress page shows. */
class QuestionDetailsTest extends TestCase
{
    public function test_a_question_the_ai_answered_stays_open_until_the_student_says_it_clicked(): void
    {
        $question = fn (string $status, ?string $by) => new QuestionDetails('q', 'w', null, 'Why?', false, 'open', [], '2026-10-16T09:00:00Z', status: $status, answeredBy: $by);

        $this->assertTrue($question('pending', null)->open());
        $this->assertTrue($question('stuck', null)->open());
        $this->assertTrue($question('answered', 'ai')->open());
        $this->assertSame('open', $question('answered', 'ai')->shown());
        $this->assertFalse($question('answered', 'student')->open());
        $this->assertSame('understood', $question('answered', 'student')->shown());
    }
}
