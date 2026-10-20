<?php

namespace App\Console\Commands;

use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\ContentCipher;
use App\Platform\Database\ContentColumns;
use App\Platform\Database\LearnerTables;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class EncryptLearnerContent extends Command
{
    protected $signature = 'vistud:encrypt:backfill {--dry-run} {--verify} {--decrypt} {--backup-confirmed} {--learner=} {--table=*} {--batch=100} {--limit=0}';

    protected $description = 'Count, encrypt or verify S2a personal text; resumable by committed row version.';

    public function handle(ContentCipher $cipher): int
    {
        $dry = (bool) $this->option('dry-run');
        $verify = (bool) $this->option('verify');
        $decrypt = (bool) $this->option('decrypt');
        if (($dry && ($verify || $decrypt)) || ($verify && $decrypt)) {
            $this->error('Choose one of dry-run, verify or decrypt.');

            return self::FAILURE;
        }
        if (! app()->runningUnitTests() && ! ($dry || $verify)
            && (! app()->isDownForMaintenance() || ! $this->option('backup-confirmed'))) {
            $this->error('Maintenance, stopped workers and a confirmed backup are required.');

            return self::FAILURE;
        }
        $tables = $this->option('table') ?: array_keys(ContentColumns::S2A);
        if (array_diff($tables, array_keys(ContentColumns::S2A)) !== [] || (int) $this->option('batch') < 1 || (int) $this->option('limit') < 0) {
            $this->error('Choose registered S2a tables and positive batch sizes.');

            return self::FAILURE;
        }
        $system = Principal::system();
        $failures = $changed = 0;
        $learners = [];
        $stopped = false;
        foreach ($tables as $table) {
            $counts = ['rows' => 0, 'cells' => 0, 'legacy' => 0, 'encrypted' => 0, 'changed' => 0, 'blocked' => 0];
            foreach (LearnerTables::contentLearnersForMaintenance($system, $table) as $learnerId) {
                if ($this->option('learner') !== null && $learnerId !== $this->option('learner')) {
                    continue;
                }
                if (! is_string($learnerId) || ! DB::table('learners')->where('id', $learnerId)->exists()) {
                    $orphans = LearnerTables::contentOrphansForMaintenance($system, $table, $learnerId);
                    foreach (ContentColumns::primary($table) as $key) {
                        $orphans->orderBy($key);
                    }
                    foreach ($orphans->cursor() as $row) {
                        $counts['rows']++;
                        $counts['blocked']++;
                        $counts[(int) $row->content_version === 0 ? 'legacy' : 'encrypted']++;
                        foreach (ContentColumns::fields($table) as $field) {
                            $counts['cells'] += $row->$field !== null ? 1 : 0;
                        }
                        $failures++;
                    }

                    continue;
                }
                $learners[$learnerId] = true;
                $scope = LearnerScope::forJob($learnerId);
                $query = fn () => LearnerTables::contentForMaintenance($system, $scope, $table);
                $keys = ContentColumns::primary($table);
                // Maintenance prevents concurrent inserts. Composite keys use
                // a lexicographic cursor, never OFFSET; rescans find version 0.
                $last = null;
                do {
                    $batch = $query();
                    foreach ($keys as $key) {
                        $batch->orderBy($key);
                    }
                    if ($last !== null) {
                        $batch->where(function ($q) use ($keys, $last) {
                            foreach ($keys as $index => $key) {
                                $q->orWhere(function ($part) use ($keys, $last, $index, $key) {
                                    for ($i = 0; $i < $index; $i++) {
                                        $part->where($keys[$i], $last[$keys[$i]]);
                                    }
                                    $part->where($key, '>', $last[$key]);
                                });
                            }
                        });
                    }
                    $rows = $batch->limit((int) $this->option('batch'))->get();
                    foreach ($rows as $row) {
                        $last = array_intersect_key((array) $row, array_flip($keys));
                        $counts['rows']++;
                        foreach (ContentColumns::fields($table) as $field) {
                            $counts['cells'] += $row->$field !== null ? 1 : 0;
                        }
                        $counts[(int) $row->content_version === 0 ? 'legacy' : 'encrypted']++;
                        try {
                            $scope->key('content');
                            if ($dry || $verify) {
                                if ((int) $row->content_version !== 0) {
                                    $cipher->decodeRow($scope, $table, (array) $row);
                                } elseif ($verify) {
                                    throw new RuntimeException('Unconverted row.');
                                }
                            } else {
                                $didChange = $query()->getConnection()->transaction(function () use ($query, $keys, $last, $scope, $table, $cipher, $decrypt) {
                                    $locked = $query();
                                    foreach ($keys as $key) {
                                        $locked->where($key, $last[$key]);
                                    }
                                    $row = (array) ($locked->lockForUpdate()->first() ?? throw new RuntimeException('Row disappeared.'));
                                    if ((int) $row['content_version'] !== 0) {
                                        $plain = $cipher->decodeRow($scope, $table, $row);
                                        if (! $decrypt) {
                                            return false;
                                        }
                                        $replacement = $plain;
                                        $replacement['content_version'] = 0;
                                    } else {
                                        if ($decrypt) {
                                            return false;
                                        }
                                        $plain = $row;
                                        if (in_array('content_identity', ContentColumns::identity($table), true)) {
                                            $plain['content_identity'] = (string) Str::uuid();
                                        }
                                        $replacement = $cipher->encodeRow($scope, $table, $plain);
                                    }
                                    $fields = [...ContentColumns::fields($table), 'content_version'];
                                    if (array_key_exists('content_identity', $replacement)) {
                                        $fields[] = 'content_identity';
                                    }
                                    $locked->update(array_intersect_key($replacement, array_flip($fields)));
                                    $stored = (array) $locked->first();
                                    $checked = $decrypt ? $stored : $cipher->decodeRow($scope, $table, $stored);
                                    foreach (ContentColumns::fields($table) as $field) {
                                        if ($checked[$field] !== $plain[$field]) {
                                            throw new RuntimeException('Round-trip verification failed.');
                                        }
                                    }

                                    return true;
                                });
                                $counts['changed'] += $didChange ? 1 : 0;
                                $changed += $didChange ? 1 : 0;
                            }
                        } catch (\Throwable) {
                            $counts['blocked']++;
                            $failures++;
                        }
                        if (! $dry && ! $verify && (int) $this->option('limit') > 0 && $changed >= (int) $this->option('limit')) {
                            $stopped = true;
                            break 2;
                        }
                    }
                } while ($rows->count() === (int) $this->option('batch'));
                app()->forgetScopedInstances();
                if ($stopped) {
                    break;
                }
            }
            $this->line($table.': '.implode(', ', array_map(fn ($key, $value) => $key.'='.$value, array_keys($counts), $counts)));
            if ($stopped) {
                break;
            }
        }
        $this->line('learners='.count($learners).', changed='.$changed.', blocked='.$failures.($stopped ? ', stopped at requested limit' : ''));

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
