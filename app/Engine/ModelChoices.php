<?php

namespace App\Engine;

use App\Platform\Access\Principal;

/**
 * The models a student can pick for one piece of work on a screen (reading an assignment, planning it): the ones
 * in their chat's quick-switch list, their three role models (so a reader model set only for reading is offered) and
 * the one already chosen, each with its name and price. A pick is checked against this list, so a request can't
 * name any model it likes. The empty choice always means the tutor's model from their AI settings.
 */
final class ModelChoices
{
    public function __construct(private Settings $settings, private Models $models) {}

    /**
     * @return list<array{id: string, name: string, price: ?string}>
     */
    public function options(Principal $by, ?string $chosen = null): array
    {
        $choices = $this->settings->get($by);
        $known = [];
        foreach ($this->models->all(key: $this->settings->credential($by)) as $model) {
            $known[$model->id] = $model;
        }
        $ids = array_values(array_unique(array_filter([
            ...$choices->chatModels(),
            $choices->tutorModel, $choices->readerModel, $choices->helperModel,
            $chosen,
        ])));

        return array_map(fn (string $id) => [
            'id' => $id,
            'name' => self::name($known[$id]->name ?? '', $id),
            'price' => ($known[$id] ?? null)?->priceWords(),
        ], $ids);
    }

    /** Whether a pick is the empty choice (the tutor's model) or one of the models on offer. */
    public function allows(Principal $by, string $id): bool
    {
        return $id === '' || in_array($id, array_column($this->options($by), 'id'), true);
    }

    /** What the empty choice stands for, as a name: "Claude 3.5 Sonnet". */
    public function defaultName(Principal $by): string
    {
        $id = $this->settings->get($by)->tutorModel;

        return $id === '' ? 'your tutor model' : self::name($this->models->find($id, $this->settings->credential($by))->name ?? '', $id);
    }

    /** A model's name without its maker's prefix ("Anthropic: Claude 3.5 Sonnet" is "Claude 3.5 Sonnet"). */
    private static function name(string $raw, string $id): string
    {
        $name = trim((string) preg_replace('/^(Anthropic|Google|OpenAI|Meta|DeepSeek|Mistral|Cohere|Qwen):\s*/i', '', trim($raw)));

        return $name !== '' ? $name : (str_contains($id, '/') ? explode('/', $id, 2)[1] : $id);
    }
}
