<?php

namespace App\Platform\Access;

use App\Platform\Database\LearnerTables;
use App\Platform\Errors\Unavailable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use JsonException;

/** Persistent wrapped keys. Provisioning is explicit; reading never recreates a missing key. */
final class LearnerKeyStore
{
    public const VERSION = 1;

    public function __construct(private MasterKeys $masters) {}

    /** Insert once, without overwriting a winner from another provisioner. */
    public function provision(LearnerScope $scope): bool
    {
        $ring = $this->masters->ring();
        $master = array_key_first($ring);
        $payload = json_encode([
            'learner_id' => $scope->learnerId, 'version' => self::VERSION,
            'master_version' => $master, 'key' => base64_encode(random_bytes(32)),
        ], JSON_THROW_ON_ERROR);
        $inserted = LearnerTables::query($scope, 'learner_keys')->insertOrIgnore([
            'learner_id' => $scope->learnerId, 'key_version' => self::VERSION,
            'master_version' => $master, 'wrapped_key' => $ring[$master]->encryptString($payload),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Read back and authenticate the existing row, including when another insert won.
        $this->load($scope);

        return $inserted === 1;
    }

    public function load(LearnerScope $scope): string
    {
        $ring = $this->masters->ring();
        $row = LearnerTables::query($scope, 'learner_keys')->first();
        if ($row === null || ! isset($ring[$row->master_version])) {
            throw self::unavailable();
        }
        try {
            $payload = json_decode($ring[$row->master_version]->decryptString($row->wrapped_key), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw self::unavailable();
        }
        if (! is_array($payload) || ($payload['learner_id'] ?? null) !== $scope->learnerId
            || ($payload['version'] ?? null) !== self::VERSION || (int) $row->key_version !== self::VERSION
            || ($payload['master_version'] ?? null) !== $row->master_version) {
            throw self::unavailable();
        }
        $key = is_string($payload['key'] ?? null) ? base64_decode($payload['key'], true) : false;
        if ($key === false || strlen($key) !== 32) {
            throw self::unavailable();
        }

        return $key;
    }

    /** One atomic row at a time; interruption leaves both old and new versions readable. */
    public function rotate(LearnerScope $scope): bool
    {
        $ring = $this->masters->ring();
        $active = array_key_first($ring);

        return DB::connection('mysql_keys')->transaction(function () use ($scope, $ring, $active) {
            $row = LearnerTables::query($scope, 'learner_keys')->lockForUpdate()->first();
            $key = $this->load($scope);
            if ($row->master_version === $active) {
                return false;
            }
            $payload = json_encode([
                'learner_id' => $scope->learnerId, 'version' => self::VERSION,
                'master_version' => $active, 'key' => base64_encode($key),
            ], JSON_THROW_ON_ERROR);
            LearnerTables::query($scope, 'learner_keys')->update([
                'master_version' => $active, 'wrapped_key' => $ring[$active]->encryptString($payload), 'updated_at' => now(),
            ]);

            return true;
        }, 3);
    }

    private static function unavailable(): Unavailable
    {
        return new Unavailable('learner_key_unavailable', 'The study keys are unavailable.');
    }
}
