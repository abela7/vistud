<?php

namespace Tests\Feature\Engine;

use App\Engine\Settings;
use App\Platform\Errors\Unprocessable;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** A student's engine settings: their models, their limits, and their consent (docs/specs/study-memory.md §6). */
class SettingsTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    public function test_the_defaults_come_from_the_owner_until_the_student_chooses_and_a_choice_is_kept(): void
    {
        config(['vistud.engine.tutor_model' => 'anthropic/claude-sonnet-4.5', 'vistud.engine.quick_model' => '']);
        $by = $this->principal($this->student());
        $settings = app(Settings::class);

        $choices = $settings->get($by);
        $this->assertSame(['anthropic/claude-sonnet-4.5', '', '', Settings::DEFAULT_SESSION_CAP, Settings::DEFAULT_MONTH_CAP, true, null], [$choices->tutorModel, $choices->quickModel, $choices->fallbackModel, $choices->sessionCapMicros, $choices->monthCapMicros, $choices->noTraining, $choices->consentedAt]);
        $this->assertFalse($choices->ready());
        $this->assertSame('anthropic/claude-sonnet-4.5', $choices->quickOrTutor());

        $choices = $settings->set($by, ['tutor_model' => ' openai/gpt-4.1-mini ', 'quick_model' => 'google/gemini-2.5-flash', 'fallback_model' => '', 'session_cap' => '$1.50', 'month_cap' => '', 'no_training' => '1', 'consent' => true]);
        $this->assertSame(['openai/gpt-4.1-mini', 'google/gemini-2.5-flash', '', 1_500_000, Settings::DEFAULT_MONTH_CAP, true], [$choices->tutorModel, $choices->quickModel, $choices->fallbackModel, $choices->sessionCapMicros, $choices->monthCapMicros, $choices->noTraining]);
        $this->assertNotNull($choices->consentedAt);
        $this->assertTrue($choices->ready());
        $this->assertSame('google/gemini-2.5-flash', $choices->quickOrTutor());

        // Changing the models keeps the consent; taking the consent back removes it.
        $consentedAt = $choices->consentedAt;
        $this->assertSame($consentedAt, $settings->set($by, ['tutor_model' => 'openai/gpt-4.1'])->consentedAt);
        $this->assertNull($settings->set($by, ['tutor_model' => 'openai/gpt-4.1', 'consent' => false])->consentedAt);

        // Another student has their own.
        $this->assertSame('anthropic/claude-sonnet-4.5', $settings->get($this->principal($this->student()))->tutorModel);
    }

    public function test_a_model_id_and_the_limits_are_checked(): void
    {
        $by = $this->principal($this->student());
        try {
            app(Settings::class)->set($by, ['tutor_model' => 'not a model id!', 'session_cap' => '-1', 'month_cap' => 'lots']);
            $this->fail('Should refuse.');
        } catch (Unprocessable $e) {
            $this->assertSame(['tutor_model', 'session_cap', 'month_cap'], array_keys($e->details['fields']));
        }
    }
}
