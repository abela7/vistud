<?php

namespace App\Console\Commands;

use App\Identity\AccountFactory;
use App\Models\User;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Ids;
use App\Study\Flashcards;
use App\Study\Questions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Once, after deleting a flashcard or a question became for good (2026-10-12): the ones deleted before were only
 * hidden, and a card's answers still counted for its topic. Each goes now as a deleted one does
 * (App\Study\Flashcards::purgeHidden, App\Study\Questions::purgeHidden). Safe to run again: a second run finds nothing.
 */
#[Signature('vistud:purge-deleted {email? : One student; every learner when left out}')]
#[Description('Delete for good the flashcards and questions that were only hidden')]
class PurgeDeleted extends Command
{
    public function handle(Flashcards $flashcards, Questions $questions): int
    {
        $email = $this->argument('email');
        if (is_string($email) && $email !== '') {
            $user = User::query()->where('email', AccountFactory::normaliseEmail($email))->first();
            $learners = $user === null ? [] : DB::table('learners')->where('user_id', $user->id)->pluck('id')->all();
            if ($learners === []) {
                $this->error('No student account has that email address.');

                return self::FAILURE;
            }
        } else {
            $learners = DB::table('learners')->orderBy('id')->pluck('id')->all();
        }

        $by = Principal::system(requestId: Ids::new());
        [$cards, $asked] = [0, 0];
        foreach ($learners as $learnerId) {
            $scope = LearnerScope::forJob((string) $learnerId);
            [$c, $q] = [$flashcards->purgeHidden($scope, $by), $questions->purgeHidden($scope)];
            if ($c + $q > 0 || $this->output->isVerbose()) {
                $this->line("{$learnerId}: {$c} ".($c === 1 ? 'card' : 'cards').", {$q} ".($q === 1 ? 'question' : 'questions').' deleted.');
            }
            [$cards, $asked] = [$cards + $c, $asked + $q];
        }

        $this->info("Deleted {$cards} hidden ".($cards === 1 ? 'card' : 'cards')." and {$asked} hidden ".($asked === 1 ? 'question' : 'questions').' for good.');

        return self::SUCCESS;
    }
}
