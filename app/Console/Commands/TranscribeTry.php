<?php

namespace App\Console\Commands;

use App\Engine\Choices;
use App\Engine\Jobs\Run;
use App\Engine\Jobs\Runner;
use App\Engine\Jobs\TranscribeClass;
use App\Engine\Role;
use App\Identity\PrincipalFactory;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Platform\Errors\AppError;
use App\Study\AudioPieces;
use App\Study\ClassRecordings;
use App\Study\ClassSessions;
use App\Study\Fence;
use App\Study\Files;
use App\Study\Modules;
use App\Study\Workspaces;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * A try-out of class transcription before it has a button: one class's recording is cut into pieces (ffmpeg), each
 * piece is written down word for word by an audio model, then the whole transcript is turned into lecture notes by
 * the student's reader model. Both results are written to a file and printed with what each step cost. The class,
 * its recording and its note are not changed; the runs are recorded and counted against the month's limit like any.
 */
#[Signature('vistud:transcribe:try {class : The class\'s id} {--user= : The student\'s email; the only account when there is one} {--model=google/gemini-2.5-flash : An audio model on the service} {--minutes=10 : How long each piece is}')]
#[Description('Try transcribing one class recording: a word-for-word transcript, then lecture notes, with the cost')]
class TranscribeTry extends Command
{
    public function handle(PrincipalFactory $principals, ClassSessions $classes, ClassRecordings $recordings, Workspaces $workspaces, Modules $modules, Runner $runner, AudioPieces $pieces): int
    {
        $email = (string) $this->option('user');
        $user = $email !== '' ? User::query()->where('email', $email)->first() : (User::query()->count() === 1 ? User::query()->first() : null);
        if ($user === null) {
            $this->error($email !== '' ? "No account has the email {$email}." : 'Say whose class it is with --user=email.');

            return self::FAILURE;
        }
        $by = $principals->forUser($user, 'console');
        $minutes = max(1, min(20, (int) $this->option('minutes')));
        $model = (string) $this->option('model');

        try {
            $class = $classes->find($by, (string) $this->argument('class'));
            $parts = array_values(array_filter($recordings->forClass($by, $class->id), fn ($p) => $p->finishedAt !== null && $p->deletedAt === null && $p->path !== null));
            if ($parts === []) {
                $this->error('This class has no finished recording.');

                return self::FAILURE;
            }
            $about = 'Course: '.$workspaces->find($by, $class->workspaceId)->name
                .($class->moduleId !== null ? '. Module: '.$modules->find($by, $class->moduleId)->title : '')
                .'. Class: '.$class->title.'.';

            $work = storage_path('app/transcribe-try/'.$class->id.'-'.now()->format('Ymd-His'));
            [$transcript, $hearCost, $offset] = ['', 0, 0];
            foreach ($parts as $part) {
                $this->line('Cutting take '.$part->position.' into '.$minutes.'-minute pieces…');
                $files = $pieces->split(Files::disk()->path($part->path), $minutes * 60, $work.'/take-'.$part->position);
                foreach ($files as $i => $file) {
                    $from = $offset + $i * $minutes * 60;
                    $heading = '## '.self::clock($from).'–'.self::clock($from + $minutes * 60);
                    $this->line('Writing down '.self::clock($from).'…');
                    [$text, $cost] = $this->hear($runner, $by, $class->workspaceId, $class->id, $model, $about, $file);
                    $hearCost += $cost;
                    $transcript .= "{$heading}\n\n{$text}\n\n";
                }
                $offset += $part->durationSeconds > 0 ? $part->durationSeconds : count($files) * $minutes * 60;
            }

            $this->line('Turning the transcript into lecture notes…');
            $notesReply = $runner->run($by, Role::Reader, 'transcribe_notes', $class->workspaceId, 'class_session', $class->id,
                fn (Run $run) => $run->ask(TranscribeClass::NOTES."\n\n".$about, Fence::wrap($transcript), 6000)->whole());
            $notes = $notesReply->text;
            $notesCost = (int) ($notesReply->costMicros ?? 0);
        } catch (AppError $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage().' Install ffmpeg (winget install Gyan.FFmpeg), or set VISTUD_FFMPEG_BINARY to where it is.');

            return self::FAILURE;
        }

        File::ensureDirectoryExists($work);
        File::put($work.'/transcript.md', "# Transcript: {$class->title}\n\n{$transcript}");
        File::put($work.'/notes.md', "# Notes: {$class->title}\n\n{$notes}\n");
        $this->newLine();
        $this->line($notes);
        $this->newLine();
        $this->comment('Transcript ('.$model.'): '.Choices::dollars($hearCost).' · notes: '.Choices::dollars($notesCost).' · together: '.Choices::dollars($hearCost + $notesCost));
        $this->comment('Both are in '.$work.' (transcript.md, notes.md). Nothing in ViStud was changed.');

        return self::SUCCESS;
    }

    /** @return array{0: string, 1: int} one piece written down, and what it cost */
    private function hear(Runner $runner, Principal $by, string $workspaceId, string $classId, string $model, string $about, string $file): array
    {
        $message = [['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => 'Write down this piece of the class. '.$about],
            ['type' => 'input_audio', 'input_audio' => ['data' => base64_encode((string) file_get_contents($file)), 'format' => 'mp3']],
        ]]];
        $reply = $runner->run($by, Role::Reader, 'transcribe_piece', $workspaceId, 'class_session', $classId,
            fn (Run $run) => $run->ask(TranscribeClass::HEAR, $message, 6000)->whole(), model: $model);

        return [trim($reply->text), (int) ($reply->costMicros ?? 0)];
    }

    private static function clock(int $seconds): string
    {
        return $seconds >= 3600 ? sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60) : sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
