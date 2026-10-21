<?php

namespace App\Identity;

use App\Engine\Credential;
use App\Engine\Models;
use App\Engine\Providers;
use App\Jobs\ExtractFileText;
use App\Jobs\MakeFilePreview;
use App\Models\User;
use App\Platform\Access\LearnerKeys;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\Forbidden;
use App\Platform\Files\TempWorkspace;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;
use Throwable;

/** Privileged, delayed, idempotent erasure. Does not decrypt study content. */
class Erasure
{
    public function erase(Principal $by, string $requestId): bool
    {
        if (! $by->isSystem()) {
            throw new Forbidden('system_required');
        }
        $db = DB::connection(config('vistud.database.owner_connection'));
        $request = $db->table('erasure_requests')->where('id', $requestId)->first();
        if ($request === null) {
            return true;
        }
        $intent = app(ErasureLedger::class)->intent($request);
        if ($intent['request_id'] !== $request->id) {
            throw new RuntimeException('Restore replay is required.');
        }
        if ($request->completed_at !== null) {
            app(ErasureLedger::class)->completed($request, $request->completed_at);

            return true;
        }
        if (now()->lt($request->not_before)) {
            return false;
        }
        // Unique delivery is only an optimisation; this lock protects manual retries too.
        $lock = Cache::lock('vistud:erase:'.$requestId, 400);
        if (! $lock->get()) {
            return false;
        }
        try {
            $user = $db->table('users')->where('id', $request->user_id)->first();
            if ($user !== null && $user->status !== AccountStatus::Deleted->value) {
                throw new RuntimeException('account_not_deleted');
            }
            $scope = $request->learner_ref === null ? null : LearnerScope::forJob($request->learner_ref);
            $this->stage($requestId, 'access');
            $this->tokens($request->user_id);
            $this->queued($request->user_id, $request->learner_ref);
            $this->caches($scope);
            $this->checkCanonicalPaths($by, $scope);

            $this->stage($requestId, 'content');
            $this->content($by, $scope);
            $this->identity($request->user_id, $user?->email);
            $this->audit($request->user_id, $request->learner_ref);

            $this->stage($requestId, 'files');
            $this->files($request->learner_ref);
            $this->temps();
            $this->verifyContent($by, $scope);

            $this->stage($requestId, 'key');
            if ($scope !== null) {
                LearnerTables::query($scope, 'learner_keys')->delete();
                if (LearnerTables::query($scope, 'learner_keys')->exists()) {
                    throw new RuntimeException('key_cleanup_pending');
                }
            }
            app(LearnerKeys::class)->forget();

            // A stale request can still have inserted metadata during the grace period.
            // This pass needs neither the learner row nor its key.
            $this->stage($requestId, 'sweep');
            $this->content($by, $scope);
            $this->tokens($request->user_id);
            $this->queued($request->user_id, $request->learner_ref);
            $this->caches($scope);
            $this->files($request->learner_ref);
            $this->audit($request->user_id, $request->learner_ref);
            if ($scope !== null) {
                $db->table('learners')->where('id', $scope->learnerId)->delete();
            }
            $db->table('users')->where('id', $request->user_id)->delete();
            $this->verifyContent($by, $scope);

            $this->stage($requestId, 'complete');
            $erasedAt = now()->toDateTimeString();
            app(ErasureLedger::class)->completed($request, $erasedAt);
            $db->transaction(function () use ($db, $request, $erasedAt) {
                $db->table('erasure_log')->insertOrIgnore([
                    'request_id' => $request->id, 'user_id' => $request->user_id,
                    'learner_ref' => $request->learner_ref, 'requested_at' => $request->requested_at,
                    'erased_at' => $erasedAt, 'version' => 1,
                ]);
                $db->table('erasure_requests')->where('id', $request->id)->update([
                    'completed_at' => now(), 'stage' => 'complete', 'error_code' => null,
                ]);
            });

            return true;
        } catch (Throwable $error) {
            $db->table('erasure_requests')->where('id', $requestId)->update(['error_code' => $error->getMessage() === 'shared_batch_cleanup_pending' ? 'shared_batch_cleanup_pending' : 'erasure_retry_required']);
            // Do not persist exception text, payloads, provider errors or paths.
            throw new RuntimeException('Erasure needs another attempt.');
        } finally {
            app(LearnerKeys::class)->forget();
            $lock->release();
        }
    }

    private function stage(string $id, string $stage): void
    {
        DB::connection(config('vistud.database.owner_connection'))->table('erasure_requests')->where('id', $id)->update(['stage' => $stage, 'error_code' => null]);
        event(new ErasureStage($id, $stage));
    }

    private function content(Principal $by, ?LearnerScope $scope): void
    {
        if ($scope === null) {
            return;
        }
        // The registry is dependency-ordered: children precede parents in reverse.
        foreach (array_reverse(LearnerTables::TABLES) as $table) {
            LearnerTables::forErasure($by, $scope, $table)->delete();
        }
    }

    private function verifyContent(Principal $by, ?LearnerScope $scope): void
    {
        if ($scope !== null) {
            foreach (LearnerTables::TABLES as $table) {
                if (LearnerTables::forErasure($by, $scope, $table)->exists()) {
                    throw new RuntimeException('content_cleanup_pending');
                }
            }
        }
    }

    private function tokens(string $userId): void
    {
        $db = DB::connection(config('vistud.database.owner_connection'));
        foreach ($db->table('oauth_access_tokens')->where('user_id', $userId)->orderBy('id')->cursor() as $token) {
            $db->table('oauth_refresh_tokens')->where('access_token_id', $token->id)->delete();
        }
        $db->table('oauth_access_tokens')->where('user_id', $userId)->delete();
        $db->table('oauth_auth_codes')->where('user_id', $userId)->delete();
        // Registered shared MCP clients have no user owner and must survive.
        $db->table('oauth_clients')->where('owner_type', User::class)->where('owner_id', $userId)->delete();
        $db->table('sessions')->where('user_id', $userId)->delete();
    }

    private function identity(string $userId, ?string $email): void
    {
        $db = DB::connection(config('vistud.database.owner_connection'));
        if ($email !== null) {
            $db->table('password_reset_tokens')->where('email', $email)->delete();
            $db->table('invitations')->where('email', $email)->delete();
        }
        $db->table('invitations')->where('accepted_user_id', $userId)->delete();
        $db->table('invitations')->where('invited_by', $userId)->update(['invited_by' => null]);
        $db->table('user_roles')->where('granted_by', $userId)->update(['granted_by' => null]);
        $db->table('user_roles')->where('user_id', $userId)->delete();
        $db->table('platform_settings')->where('updated_by', $userId)->update(['updated_by' => null]);
        // Keep only the blocked login row until the key and final sweep are complete.
        $db->table('users')->where('id', $userId)->update([
            'name' => 'Deleted account', 'email' => $userId.'@erased.invalid',
            'password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT),
            'remember_token' => null, 'two_factor_secret' => null,
            'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null,
        ]);
    }

    private function caches(?LearnerScope $scope): void
    {
        if ($scope === null) {
            return;
        }
        foreach (LearnerTables::forErasure(Principal::system(), $scope, 'files')->orderBy('id')->select('id')->cursor() as $file) {
            foreach ([MakeFilePreview::class, ExtractFileText::class] as $jobClass) {
                Cache::lock('laravel_unique_job:'.$jobClass.':'.$file->id)->forceRelease();
            }
        }
        $row = LearnerTables::forErasure(Principal::system(), $scope, 'engine_settings')->first(['key_encrypted', 'key_provider']);
        if ($row?->key_encrypted !== null) {
            $key = Credential::pack($row->key_provider ?? Providers::OPENROUTER, Crypt::decryptString($row->key_encrypted));
            $catalogue = Models::cacheKey($key);
            if ($catalogue !== Models::cacheKey(null)) {
                Cache::forget($catalogue);
            }
        }
        $prefixes = ['topic-again:'.$scope->learnerId.':', 'topic-review:'.$scope->learnerId.':', 'topic-files-out:'.$scope->learnerId.':', 'engine-trouble:'.$scope->learnerId];
        $store = Cache::getStore();
        if ($store instanceof DatabaseStore) {
            foreach ([[config('cache.stores.database.table') ?: 'cache', $store->getConnection()], [config('cache.stores.database.lock_table') ?: 'cache_locks', $store->getLockConnection()]] as [$table, $connection]) {
                $connection->table($table)->where(function ($q) use ($store, $prefixes, $scope) {
                    foreach ($prefixes as $prefix) {
                        $q->orWhere('key', 'like', addcslashes($store->getPrefix().$prefix, '%_\\').'%');
                    }
                    $q->orWhere('key', 'like', '%'.addcslashes('learners/'.$scope->learnerId.'/', '%_\\').'%');
                })->delete();
            }
        } elseif ($store instanceof ArrayStore) {
            foreach (array_keys($store->all()) as $key) {
                if (str_contains($key, 'learners/'.$scope->learnerId.'/')) {
                    Cache::forget($key);
                }
                foreach ($prefixes as $prefix) {
                    if (str_starts_with($key, $prefix)) {
                        Cache::forget($key);
                    }
                }
            }
        } else {
            throw new RuntimeException('unsupported_erasure_cache');
        }
        // Clear the hashed limiter keys without affecting other learners/IP limits.
        foreach (['mcp:'.$scope->learnerId, 'mcp:mcp:'.$scope->learnerId, md5('mcpmcp:'.$scope->learnerId)] as $limiter) {
            RateLimiter::clear($limiter);
        }
    }

    private function queued(string $userId, ?string $learnerId): void
    {
        $db = DB::connection(config('vistud.database.owner_connection'));
        foreach (['jobs', 'failed_jobs'] as $table) {
            $column = 'payload';
            // Scan plain queue metadata; never unserialize untrusted payloads.
            $db->table($table)->where(function ($q) use ($column, $userId, $learnerId) {
                $q->where($column, 'like', '%'.addcslashes($userId, '%_\\').'%');
                if ($learnerId !== null) {
                    $q->orWhere($column, 'like', '%'.addcslashes($learnerId, '%_\\').'%');
                }
            })->delete();
        }
        // Batch callbacks can be shared by several learners. Preserve the batch
        // and other learners' references. Equal-length UUID replacement keeps
        // PHP's serialized string lengths valid without instantiating any object.
        foreach ($db->table('job_batches')->orderBy('id')->cursor() as $batch) {
            if ($batch->options === null) {
                continue;
            }
            $options = str_replace(array_filter([$userId, $learnerId]), '00000000-0000-0000-0000-000000000000', $batch->options);
            if ($options !== $batch->options) {
                // Serialized objects may contain signed callbacks. Editing their
                // bytes would invalidate the signature for other learners too.
                if (preg_match('/(?:O|C):[0-9]+:/', $batch->options)) {
                    throw new RuntimeException('shared_batch_cleanup_pending');
                }
                $db->table('job_batches')->where('id', $batch->id)->update(['options' => $options]);
            }
        }
    }

    private function files(?string $learnerId): void
    {
        if ($learnerId === null) {
            return;
        }
        if (preg_match('/^[a-f0-9-]{36}$/D', $learnerId) !== 1) {
            throw new RuntimeException('invalid_erasure_path');
        }
        $path = 'learners/'.$learnerId;
        foreach (array_unique([config('vistud.files.disk'), config('vistud.files.derived_disk')]) as $diskName) {
            $disk = Storage::disk($diskName);
            if (! $disk->getAdapter() instanceof LocalFilesystemAdapter) {
                throw new RuntimeException('unsupported_erasure_disk');
            }
            $root = realpath($disk->path(''));
            $target = realpath($disk->path($path));
            if ($target !== false) {
                $prefix = rtrim(str_replace('\\', '/', (string) $root), '/').'/';
                if ($root === false || ! str_starts_with(strtolower(str_replace('\\', '/', $target)).'/', strtolower($prefix)) || is_link($disk->path($path))) {
                    throw new RuntimeException('unsafe_erasure_path');
                }
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS)) as $entry) {
                    if ($entry->isLink()) {
                        throw new RuntimeException('unsafe_erasure_path');
                    }
                }
            }
            if ($disk->directoryExists($path) && ! $disk->deleteDirectory($path)) {
                throw new RuntimeException('file_cleanup_pending');
            }
            if ($disk->directoryExists($path)) {
                throw new RuntimeException('file_cleanup_pending');
            }
        }
    }

    private function audit(string $userId, ?string $learnerId): void
    {
        $db = DB::connection(config('vistud.database.owner_connection'));
        foreach ($db->table('audit_log')->orderBy('id')->cursor() as $row) {
            $actor = $row->actor_user_id === $userId;
            $target = in_array($row->target_id, array_filter([$userId, $learnerId]), true);
            $metadata = $row->metadata === null ? null : json_decode($row->metadata, true);
            $changed = false;
            if (is_array($metadata)) {
                array_walk_recursive($metadata, function (&$value) use ($userId, $learnerId, &$changed) {
                    if ($value === $userId || ($learnerId !== null && $value === $learnerId)) {
                        $value = null;
                        $changed = true;
                    }
                });
            }
            if ($actor || $target || $changed) {
                $fields = ['ip' => null, 'user_agent' => null, 'request_id' => null];
                if ($actor || $target) {
                    // Lifecycle details can include a credential hint. Retain
                    // the event/action/time/role, not the erased actor's details.
                    $fields['metadata'] = null;
                }
                if ($actor) {
                    $fields['actor_user_id'] = null;
                }
                if ($target) {
                    $fields['target_id'] = null;
                }
                if ($changed && ! $actor && ! $target) {
                    $fields['metadata'] = json_encode($metadata, JSON_THROW_ON_ERROR);
                }
                $db->table('audit_log')->where('id', $row->id)->update($fields);
            }
        }
    }

    private function temps(): void
    {
        // No owner inference: only the existing age/lease rules apply globally.
        TempWorkspace::prune(strict: true);
        $disk = Storage::disk('temporary');
        foreach ($disk->allFiles('livewire-tmp') as $path) {
            if ($disk->lastModified($path) < time() - 3600) {
                if (! $disk->delete($path)) {
                    throw new RuntimeException('temporary_cleanup_pending');
                }
            }
        }
    }

    private function checkCanonicalPaths(Principal $by, ?LearnerScope $scope): void
    {
        if ($scope === null) {
            return;
        }
        foreach (['files', 'canonical_files'] as $table) {
            foreach (LearnerTables::forErasure($by, $scope, $table)->orderBy('id')->select('storage_key')->cursor() as $row) {
                if (! str_starts_with($row->storage_key, 'learners/'.$scope->learnerId.'/') || preg_match('#(?:^|/)[.]{1,2}(?:/|$)|\\\\#', $row->storage_key)) {
                    throw new RuntimeException('legacy_file_path_requires_review');
                }
            }
        }
    }
}
