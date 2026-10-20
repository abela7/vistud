<?php

namespace App\Platform\Database;

use App\Platform\Access\LearnerScope;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use LogicException;

/**
 * Internal to LearnerTables. Services exchange plaintext with this adapter;
 * SQL sees ciphertext. Only the manifest's tables use it. Metadata aggregates
 * remain ordinary SQL and do not load a learner key.
 */
final class ContentQuery extends Builder
{
    private LearnerScope $scope;

    private string $contentTable;

    private bool $storageOnly = false;

    public function forContent(LearnerScope $scope, string $table): self
    {
        $this->scope = $scope;
        $this->contentTable = $table;

        return $this;
    }

    public function newQuery()
    {
        return (new self($this->connection, $this->grammar, $this->processor))->forContent($this->scope, $this->contentTable);
    }

    public function get($columns = ['*'])
    {
        if ($this->storageOnly || $this->aggregate !== null) {
            return parent::get($columns);
        }
        $selected = $this->columns ?? Arr::wrap($columns);
        $this->assertMetadataOperations();
        $fields = ContentColumns::fields($this->contentTable);
        $all = in_array('*', $selected, true) || in_array($this->contentTable.'.*', $selected, true);
        $content = $all || array_intersect($fields, array_filter($selected, 'is_string')) !== [];
        foreach ($selected as $column) {
            if (! is_string($column) || str_contains(strtolower($column), ' as ') || str_contains($column, '.')) {
                if ($column !== $this->contentTable.'.*') {
                    $this->assertMetadataExpression($column);
                }
            }
        }
        if (! $content) {
            return parent::get($columns);
        }
        $required = [...ContentColumns::identity($this->contentTable), 'learner_id', 'content_version'];
        $added = $all ? [] : array_values(array_diff($required, $selected));
        $raw = $this->storageQuery();
        $raw->columns = [...$selected, ...$added];

        return $raw->get()->map(function (object $row) use ($added) {
            $plain = app(ContentCipher::class)->decodeRow($this->scope, $this->contentTable, (array) $row);
            foreach ($added as $field) {
                unset($plain[$field]);
            }

            return (object) $plain;
        });
    }

    public function pluck($column, $key = null)
    {
        if ($this->storageOnly) {
            return parent::pluck($column, $key);
        }

        return $this->get($key === null ? [$column] : [$column, $key])
            ->pluck($this->stripTableForPluck($column), $this->stripTableForPluck($key));
    }

    public function cursor()
    {
        // Use bounded, ordered lazy batches so hydration cannot be bypassed.
        if ($this->storageOnly) {
            return parent::cursor();
        }

        return $this->lazy();
    }

    public function insert(array $values)
    {
        if ($this->storageOnly || $values === []) {
            return parent::insert($values);
        }
        $rows = array_is_list($values) ? $values : [$values];
        $rows = array_map(function (array $row) {
            $row['learner_id'] = $this->scope->learnerId;
            if (in_array('content_identity', ContentColumns::identity($this->contentTable), true)) {
                // Copies get a new identity; callers cannot copy an envelope or its identity.
                $row['content_identity'] = (string) Str::uuid();
            }

            return app(ContentCipher::class)->encodeRow($this->scope, $this->contentTable, $row);
        }, $rows);

        return $this->storageQuery()->insert($rows);
    }

    public function update(array $values)
    {
        if ($this->storageOnly) {
            return parent::update($values);
        }
        foreach (['learner_id', 'content_identity', 'content_version', ...ContentColumns::primary($this->contentTable)] as $identity) {
            if (array_key_exists($identity, $values)) {
                throw new LogicException('Content row identities cannot be updated.');
            }
        }
        foreach (array_keys($values) as $field) {
            if (! in_array($field, ContentColumns::fields($this->contentTable), true)) {
                $this->assertMetadataExpression($field);
            }
        }
        $this->assertMetadataOperations();

        return $this->connection->transaction(function () use ($values) {
            $read = $this->storageQuery();
            $read->columns = null;
            $rows = $read->lockForUpdate()->get();
            $count = 0;
            foreach ($rows as $row) {
                $plain = app(ContentCipher::class)->decodeRow($this->scope, $this->contentTable, (array) $row);
                $changed = array_replace($plain, $values);
                $encrypted = app(ContentCipher::class)->encodeRow($this->scope, $this->contentTable, $changed);
                $write = $this->storageQuery();
                foreach (ContentColumns::primary($this->contentTable) as $field) {
                    $write->where($field, $row->$field);
                }
                // Re-encrypt content on copies, moves and restores, including metadata-only updates.
                $patch = $values + array_intersect_key($encrypted, array_flip(ContentColumns::fields($this->contentTable)));
                foreach (ContentColumns::fields($this->contentTable) as $field) {
                    if (array_key_exists($field, $encrypted)) {
                        $patch[$field] = $encrypted[$field];
                    }
                }
                $count += $write->update($patch);
            }

            return $count;
        });
    }

    public function toSql()
    {
        $this->assertMetadataOperations();

        return parent::toSql();
    }

    public function insertOrIgnore(array $values)
    {
        throw new LogicException('Content inserts must be explicit and checked.');
    }

    public function exists()
    {
        $this->assertMetadataOperations();

        return parent::exists();
    }

    public function aggregate($function, $columns = ['*'])
    {
        $this->assertMetadataOperations();
        foreach ($columns as $column) {
            $this->assertMetadataExpression($column);
        }

        return parent::aggregate($function, $columns);
    }

    public function delete($id = null)
    {
        $this->assertMetadataOperations();

        return parent::delete($id);
    }

    public function updateFrom(array $values)
    {
        throw new LogicException('Update content through its scoped row identity.');
    }

    public function insertOrIgnoreReturning(array $values, array $returning = ['*'], array|string|null $uniqueBy = null)
    {
        throw new LogicException('Content inserts must be explicit and checked.');
    }

    public function insertGetId(array $values, $sequence = null)
    {
        throw new LogicException('Use LearnerTables::insert for content rows.');
    }

    public function insertUsing(array $columns, $query)
    {
        throw new LogicException('Copy content through decrypted service values.');
    }

    public function insertOrIgnoreUsing(array $columns, $query)
    {
        throw new LogicException('Copy content through decrypted service values.');
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        throw new LogicException('Content upserts must resolve each row identity first.');
    }

    private function storageQuery(): self
    {
        $raw = clone $this;
        $raw->storageOnly = true;

        return $raw;
    }

    private function assertMetadataOperations(): void
    {
        foreach ($this->columns ?? [] as $column) {
            if (! is_string($column) || (str_contains(strtolower($column), ' as ') || str_contains($column, '.')) && $column !== $this->contentTable.'.*') {
                $this->assertMetadataExpression($column);
            }
        }
        foreach (['wheres', 'orders', 'groups', 'havings', 'joins', 'unions'] as $property) {
            foreach ($this->$property ?? [] as $operation) {
                $this->checkOperation($operation);
            }
        }
    }

    private function checkOperation(mixed $operation): void
    {
        if (is_string($operation) || $operation instanceof Expression) {
            $this->assertMetadataExpression($operation);
        } elseif (is_array($operation)) {
            foreach ($operation as $key => $value) {
                if (in_array($key, ['column', 'first', 'second', 'sql'], true)) {
                    $this->assertMetadataExpression($value);
                } elseif ($key === 'columns' || ($key === 'values' && ($operation['type'] ?? '') === 'BetweenColumns')) {
                    foreach ($value as $column) {
                        $this->assertMetadataExpression($column);
                    }
                } elseif ($key === 'query' && $value instanceof self) {
                    $value->assertMetadataOperations();
                } elseif ($key === 'query' && $value instanceof Builder) {
                    throw new LogicException('Content subqueries need their own learner-scoped service read.');
                }
            }
        } elseif ($operation instanceof JoinClause) {
            throw new LogicException('Join content through learner-scoped service reads.');
        }
    }

    private function assertMetadataExpression(mixed $expression): void
    {
        if ($expression instanceof Expression) {
            $expression = $expression->getValue($this->grammar);
        }
        if (! is_string($expression)) {
            throw new LogicException('Unsupported content query expression.');
        }
        foreach (ContentColumns::fields($this->contentTable) as $field) {
            if (preg_match('/\b'.preg_quote($field, '/').'\b/i', $expression)) {
                throw new LogicException('Decrypt content before searching, sorting or filtering it.');
            }
        }
    }
}
