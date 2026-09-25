<?php

namespace App\Console\Commands;

use App\Identity\Accounts;
use App\Platform\Access\Principal;
use App\Platform\Errors\AppError;
use App\Platform\Ids;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Creates an account from the server's shell (ADR 0003 §10.3, first-admin
 * setup step 1). Audited as a system action. Never grants admin: run
 * vistud:admin:grant afterwards.
 */
#[Signature('vistud:account:create {email} {--name= : Display name (asked for if missing)} {--timezone=UTC : IANA time zone} {--no-student : Create an account without a student role or learner record}')]
#[Description('Create an account (student by default). Asks for the password.')]
class CreateAccount extends Command
{
    public function handle(Accounts $accounts): int
    {
        $name = $this->option('name') ?: $this->ask('Name');
        $password = (string) $this->secret('Password (at least 12 characters)');
        if ($password !== (string) $this->secret('Repeat the password')) {
            $this->error('The passwords do not match.');

            return self::FAILURE;
        }

        try {
            $user = $accounts->create(
                Principal::system(requestId: Ids::new()),
                (string) $name,
                (string) $this->argument('email'),
                $password,
                student: ! $this->option('no-student'),
                timezone: (string) $this->option('timezone'),
            );
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                $this->error(implode(' ', $messages));
            }

            return self::FAILURE;
        } catch (AppError $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Created account {$user->id}.");

        return self::SUCCESS;
    }
}
