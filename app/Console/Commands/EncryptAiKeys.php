<?php

namespace App\Console\Commands;

use App\Engine\Settings;
use App\Platform\Access\LearnerScope;
use App\Platform\Database\ContentCipher;
use App\Platform\Database\LearnerTables;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Explicit offline, resumable conversion. Runtime never falls back to APP_KEY. */
final class EncryptAiKeys extends Command
{
    protected $signature = 'vistud:encrypt:ai-keys {--dry-run} {--decrypt : Roll back to legacy APP_KEY encryption while offline}';

    protected $description = 'Convert saved AI credentials to learner-key encryption without displaying secrets.';

    public function handle(Settings $settings, ContentCipher $cipher): int
    {
        if (! $this->option('dry-run') && ! app()->isDownForMaintenance()) {
            $this->error('Take the app offline and stop workers first.');

            return self::FAILURE;
        }
        $converted = $verified = $failed = 0;
        foreach (DB::table('learners')->orderBy('id')->cursor() as $learner) {
            $scope = LearnerScope::forJob($learner->id);
            try {
                DB::transaction(function () use ($settings, $cipher, $scope, &$converted, &$verified) {
                    $row = LearnerTables::query($scope, 'engine_settings')->lockForUpdate()->first();
                    if ($row === null || $row->key_encrypted === null) {
                        return;
                    }
                    $new = str_starts_with($row->key_encrypted, 'vsk1:');
                    $secret = $new ? $cipher->decrypt($scope, 'engine_settings', (array) $row, 'key_encrypted', substr($row->key_encrypted, 5)) : Crypt::decryptString($row->key_encrypted);
                    if ($new !== (bool) $this->option('decrypt')) {
                        $verified++;

                        return;
                    }
                    $converted++;
                    if ($this->option('dry-run')) {
                        return;
                    }
                    $value = $this->option('decrypt') ? Crypt::encryptString($secret) : $settings->seal($scope, $row, $secret);
                    LearnerTables::query($scope, 'engine_settings')->where('id', $row->id)->update(['key_encrypted' => $value]);
                    $stored = LearnerTables::query($scope, 'engine_settings')->where('id', $row->id)->first();
                    $roundTrip = $this->option('decrypt') ? Crypt::decryptString($stored->key_encrypted) : $cipher->decrypt($scope, 'engine_settings', (array) $stored, 'key_encrypted', substr($stored->key_encrypted, 5));
                    if (! hash_equals($secret, $roundTrip)) {
                        throw new \RuntimeException('Credential verification failed.');
                    }
                });
            } catch (Throwable) {
                $failed++;
            }
        }
        $this->line('convert='.$converted.' verified='.$verified.' failed='.$failed);

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
