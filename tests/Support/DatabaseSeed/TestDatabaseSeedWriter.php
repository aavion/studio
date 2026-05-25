<?php

declare(strict_types=1);

namespace App\Tests\Support\DatabaseSeed;

use PDO;
use RuntimeException;

final readonly class TestDatabaseSeedWriter
{
    public const NOW = '2026-05-23 21:00:00';

    public function __construct(
        private PDO $pdo,
    ) {
    }

    /**
     * @param array<string, mixed> $values
     */
    public function insert(string $table, array $values): void
    {
        $columns = array_keys($values);
        $placeholders = array_map(static fn (string $column): string => ':'.$column, $columns);

        $statement = $this->pdo->prepare(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders),
        ));

        foreach ($values as $column => $value) {
            $statement->bindValue(':'.$column, $value);
        }

        $statement->execute();
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $criteria
     */
    public function update(string $table, array $values, array $criteria): void
    {
        $set = array_map(static fn (string $column): string => $column.' = :set_'.$column, array_keys($values));
        $where = array_map(static fn (string $column): string => $column.' = :where_'.$column, array_keys($criteria));

        $statement = $this->pdo->prepare(sprintf(
            'UPDATE %s SET %s WHERE %s',
            $table,
            implode(', ', $set),
            implode(' AND ', $where),
        ));

        foreach ($values as $column => $value) {
            $statement->bindValue(':set_'.$column, $value);
        }

        foreach ($criteria as $column => $value) {
            $statement->bindValue(':where_'.$column, $value);
        }

        $statement->execute();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function seedStateMarker(
        string $uid,
        string $subjectType,
        string $subjectUid,
        string $markerKey,
        ?string $markerBy = null,
        ?string $markerValue = null,
        array $metadata = [],
    ): void {
        $this->insert('state_marker', [
            'uid' => $uid,
            'subject_type' => $subjectType,
            'subject_uid' => $subjectUid,
            'marker_key' => $markerKey,
            'marker_at' => self::NOW,
            'marker_by' => $markerBy,
            'marker_value' => $markerValue,
            'metadata' => $this->json($metadata),
        ]);
    }

    public function fieldValueUid(string $contentUid, int $fieldIndex): string
    {
        $contentNumber = (int) substr($contentUid, -12);

        return sprintf('40000000-0000-%04d-%04d-%012d', $contentNumber, $fieldIndex, ($contentNumber * 100) + $fieldIndex);
    }

    public function adminPasswordHash(): string
    {
        return password_hash($this->appSecret(), PASSWORD_BCRYPT, ['cost' => 4]);
    }

    public function apiKeyHmacHash(string $plainKey): string
    {
        return hash_hmac('sha256', $plainKey, $this->appSecret());
    }

    public function encryptApiKey(string $plainKey): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plainKey,
            'aes-256-gcm',
            hash('sha256', $this->appSecret(), true),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        if (false === $ciphertext) {
            throw new RuntimeException('Unable to encrypt seeded API key.');
        }

        return 'v1.'.base64_encode($nonce).'.'.base64_encode($tag).'.'.base64_encode($ciphertext);
    }

    public function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    private function appSecret(): string
    {
        $appSecret = $_SERVER['APP_SECRET'] ?? $_ENV['APP_SECRET'] ?? null;

        if (!is_string($appSecret) || '' === $appSecret) {
            throw new RuntimeException('APP_SECRET must be available to seed protected test credentials.');
        }

        return $appSecret;
    }
}
