<?php

declare(strict_types=1);

namespace GhostBot;

use PDO;
use PDOException;

final class ApiTokenService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function generate(int $userId, string $label, string $createdBy): string
    {
        $label = trim($label);
        $labelCharacters = preg_match_all('/./us', $label);
        if ($label === '' || $labelCharacters === false || $labelCharacters > 80) {
            throw new \InvalidArgumentException('API token label must be 1-80 characters.');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO api_tokens (user_id, label, token_prefix, token_hash, created_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $token = 'ghost_' . bin2hex(random_bytes(24));
            try {
                $statement->execute([
                    $userId,
                    $label,
                    substr($token, 0, 22),
                    hash('sha256', $token),
                    $createdBy,
                ]);
                return $token;
            } catch (PDOException $exception) {
                if ((string) $exception->getCode() !== '23000') {
                    throw $exception;
                }
            }
        }
        throw new \RuntimeException('Unable to generate a unique API token prefix.');
    }

    public function listForUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT label, token_prefix, created_at, last_used_at, revoked_at
             FROM api_tokens WHERE user_id = ? ORDER BY id DESC LIMIT 20'
        );
        $statement->execute([$userId]);
        return $statement->fetchAll();
    }

    public function lookup(string $prefix, ?int $ownerId = null): ?array
    {
        $sql = 'SELECT t.id, t.user_id, t.label, t.token_prefix, t.created_at, t.last_used_at,
                       t.revoked_at, u.platform_user_id
                FROM api_tokens t JOIN users u ON u.id = t.user_id
                WHERE t.token_prefix = ?';
        $params = [$prefix];
        if ($ownerId !== null) {
            $sql .= ' AND t.user_id = ?';
            $params[] = $ownerId;
        }
        $statement = $this->pdo->prepare($sql . ' LIMIT 1');
        $statement->execute($params);
        $token = $statement->fetch();
        return $token === false ? null : $token;
    }

    public function authenticate(string $token): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT u.* FROM api_tokens t JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ? AND t.revoked_at IS NULL LIMIT 1'
        );
        $statement->execute([hash('sha256', $token)]);
        $user = $statement->fetch();
        if ($user === false || (bool) $user['is_banned']) {
            return null;
        }

        $update = $this->pdo->prepare('UPDATE api_tokens SET last_used_at = UTC_TIMESTAMP() WHERE token_hash = ?');
        $update->execute([hash('sha256', $token)]);
        return $user;
    }

    public function revoke(int $tokenId): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE api_tokens SET revoked_at = UTC_TIMESTAMP() WHERE id = ? AND revoked_at IS NULL'
        );
        $statement->execute([$tokenId]);
        return $statement->rowCount() === 1;
    }
}
