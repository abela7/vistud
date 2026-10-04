<?php

namespace App\Console\Commands;

use App\Engine\Choices;
use App\Engine\SessionChat;
use App\Identity\PrincipalFactory;
use App\Models\User;
use App\Platform\Errors\AppError;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Says something in a study session's chat from the shell, as the student, and prints the tutor's answer, the
 * look-ups it made and what the turn cost: the way to try the engine before the chat has a screen.
 */
#[Signature('vistud:engine:ask {session : The study session\'s id (an open one)} {text : What to say} {--user= : The student\'s email; the only account when there is one}')]
#[Description('Say something in a study session\'s chat and print the tutor\'s answer')]
class EngineAsk extends Command
{
    public function handle(SessionChat $chat, PrincipalFactory $principals): int
    {
        $email = (string) $this->option('user');
        $user = $email !== '' ? User::query()->where('email', $email)->first() : (User::query()->count() === 1 ? User::query()->first() : null);
        if ($user === null) {
            $this->error($email !== '' ? "No account has the email {$email}." : 'Say whose session it is with --user=email.');

            return self::FAILURE;
        }
        $by = $principals->forUser($user, 'console');
        $session = (string) $this->argument('session');

        try {
            $reply = $chat->send($by, $session, (string) $this->argument('text'));
        } catch (AppError $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $turns = $chat->transcript($by, $session);
        $last = $turns[array_key_last($turns)] ?? null;
        $this->newLine();
        $this->line($reply->text !== '' ? $reply->text : '(No words in the answer.)');
        $this->newLine();
        if ($last !== null && $last['tools'] !== []) {
            $this->comment('Looked up: '.implode(', ', $last['tools']));
        }
        $spent = $chat->spent($by, $session);
        $this->comment('Model: '.$reply->model.' · this turn: '.Choices::dollars($last['cost_micros'] ?? 0).' · this session: '.Choices::dollars($spent['session']).' of '.Choices::dollars($spent['session_cap']).' · this month: '.Choices::dollars($spent['month']).' of '.Choices::dollars($spent['month_cap']));

        return self::SUCCESS;
    }
}
