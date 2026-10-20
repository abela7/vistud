<?php

namespace App\Platform\Database;

use App\Platform\Access\LearnerScope;
use RuntimeException;

/** Authenticated storage encoding; never guesses that unreadable ciphertext is legacy text. */
final class ContentCipher
{
    public function encrypt(LearnerScope $scope, string $table, array $row, string $column, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $scope->encrypter()->encryptString(json_encode([
            'v' => ContentColumns::VERSION, 'learner' => $scope->learnerId, 'table' => $table,
            'row' => $this->identity($table, $row), 'column' => $column, 'value' => $value,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function decrypt(LearnerScope $scope, string $table, array $row, string $column, ?string $value): ?string
    {
        // Even all-null content belongs to a provisioned learner.
        $encrypter = $scope->encrypter();
        if ($value === null) {
            return null;
        }
        try {
            $payload = json_decode($encrypter->decryptString($value), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($payload) || ($payload['v'] ?? null) !== ContentColumns::VERSION
                || ($payload['learner'] ?? null) !== $scope->learnerId || ($payload['table'] ?? null) !== $table
                || ($payload['row'] ?? null) !== $this->identity($table, $row) || ($payload['column'] ?? null) !== $column
                || ! is_string($payload['value'] ?? null)) {
                throw new RuntimeException;
            }

            return $payload['value'];
        } catch (\Throwable $e) {
            // No data, ciphertext or provider exception text in diagnostics.
            throw new RuntimeException('Learner content authentication failed.', previous: $e);
        }
    }

    public function encodeRow(LearnerScope $scope, string $table, array $row): array
    {
        $scope->encrypter();
        if ($table === 'topic_suggestions' && isset($row['name'])) {
            $row['name_key'] = ContentIndexes::suggestion($scope, $row['name']);
        }
        foreach (ContentColumns::fields($table) as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = $this->encrypt($scope, $table, $row, $field, $row[$field]);
            }
        }
        $row['content_version'] = ContentColumns::VERSION;

        return $row;
    }

    public function decodeRow(LearnerScope $scope, string $table, array $row): array
    {
        if (isset($row['learner_id']) && $row['learner_id'] !== $scope->learnerId) {
            throw new RuntimeException('Learner content authentication failed.');
        }
        if ((int) ($row['content_version'] ?? 0) !== ContentColumns::VERSION) {
            throw new RuntimeException('Learner content needs the verified encryption backfill.');
        }
        foreach (ContentColumns::fields($table) as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = $this->decrypt($scope, $table, $row, $field, $row[$field]);
            }
        }
        if ($table === 'topic_suggestions' && isset($row['name'], $row['name_key'])) {
            $this->verifyIndexes($scope, $table, $row);
        }

        return $row;
    }

    public function verifyIndexes(LearnerScope $scope, string $table, array $row): void
    {
        if ($table === 'topic_suggestions' && (! isset($row['name'], $row['name_key'])
            || ! hash_equals(ContentIndexes::suggestion($scope, $row['name']), $row['name_key']))) {
            throw new RuntimeException('Learner content index authentication failed.');
        }
    }

    private function identity(string $table, array $row): array
    {
        $identity = [];
        foreach (ContentColumns::identity($table) as $field) {
            if (! isset($row[$field]) || ! is_scalar($row[$field])) {
                throw new RuntimeException('Learner content row identity is missing.');
            }
            $identity[$field] = (string) $row[$field];
        }

        return $identity;
    }
}
