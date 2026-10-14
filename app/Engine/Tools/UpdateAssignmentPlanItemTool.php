<?php

declare(strict_types=1);

namespace App\Engine\Tools;

use App\Platform\Access\Principal;
use App\Study\PlanItem;
use App\Study\Plans;

/**
 * Updates the state or notes of a section, step, marking criterion, or milestone in the assignment's plan as the
 * student works through the assignment in chat.
 */
final class UpdateAssignmentPlanItemTool implements Tool
{
    public function __construct(private Plans $plans) {}

    public function name(): string
    {
        return 'update_assignment_plan_item';
    }

    public function description(): string
    {
        return 'Updates the state of a plan section, step (todo/doing/stuck/done), criterion (not_yet/partly/met), or milestone (pending/achieved).';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'item' => ['type' => 'string', 'description' => 'Title of the section, step, criterion, or milestone.'],
                'state' => [
                    'type' => 'string',
                    'enum' => ['todo', 'doing', 'stuck', 'done', 'not_yet', 'partly', 'met', 'pending', 'achieved'],
                ],
                'kind' => ['type' => 'string', 'enum' => ['part', 'step', 'criterion', 'milestone']],
                'notes' => ['type' => 'string'],
            ],
            'required' => ['item', 'state'],
            'additionalProperties' => false,
        ];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        if ($context->activityId === null) {
            return 'This can only be done in an assignment\'s chat.';
        }

        $wanted = Lookup::text($input, 'item') ?? '';
        $state = Lookup::text($input, 'state') ?? '';
        $kind = Lookup::text($input, 'kind');
        $notes = Lookup::text($input, 'notes');

        if ($wanted === '') {
            return 'Say which plan item or criterion to update.';
        }

        $plan = $this->plans->get($by, $context->activityId);
        if ($plan->empty()) {
            return 'This assignment has no plan yet. Use save_assignment_plan to create one first.';
        }

        $candidates = array_values(array_filter(
            $plan->items,
            fn (PlanItem $i) => $kind === null || $i->kind === $kind
        ));

        $found = Lookup::one($candidates, $wanted, fn (PlanItem $i) => $i->title, 'plan item');
        if (is_string($found)) {
            return $found;
        }

        $normalizedState = self::normalizeState($found->kind, $state);
        if ($normalizedState === null) {
            $allowed = match ($found->kind) {
                'criterion' => implode(', ', Plans::CRITERION_STATES),
                'milestone' => implode(', ', Plans::MILESTONE_STATES),
                default => implode(', ', Plans::STATES),
            };

            return "Invalid state \"{$state}\" for {$found->kind} \"{$found->title}\". Choose one of: {$allowed}.";
        }

        $this->plans->setState($by, $found->id, $normalizedState);
        if ($notes !== null && in_array($found->kind, ['part', 'step', 'milestone'], true)) {
            $this->plans->update($by, $found->id, ['notes' => $notes]);
        }

        $words = AssignmentPlanTool::words($normalizedState);
        $context->effects->planItemUpdated($found->id, $found->kind, $found->title, $words);

        return "Marked {$found->kind} \"{$found->title}\" as {$words}.";
    }

    private static function normalizeState(string $kind, string $state): ?string
    {
        $state = strtolower(trim($state));
        if ($kind === 'criterion') {
            $map = [
                'todo' => 'not_yet',
                'not_yet' => 'not_yet',
                'doing' => 'partly',
                'partial' => 'partly',
                'partly' => 'partly',
                'done' => 'met',
                'met' => 'met',
            ];

            return $map[$state] ?? null;
        }
        if ($kind === 'milestone') {
            $map = [
                'todo' => 'pending',
                'pending' => 'pending',
                'done' => 'achieved',
                'achieved' => 'achieved',
            ];

            return $map[$state] ?? null;
        }

        $map = [
            'todo' => 'todo',
            'not_yet' => 'todo',
            'doing' => 'doing',
            'in_progress' => 'doing',
            'partly' => 'doing',
            'stuck' => 'stuck',
            'done' => 'done',
            'met' => 'done',
            'achieved' => 'done',
        ];

        return $map[$state] ?? null;
    }
}
