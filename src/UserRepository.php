<?php

declare(strict_types=1);

namespace GhostBot;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class UserRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function upsert(string $platformUserId, ?string $username): array
    {
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO users (platform_user_id, username) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE username = VALUES(username)'
        );
        $statement->execute([$platformUserId, $username]);

        return $this->findByPlatformId($platformUserId)
            ?? throw new \RuntimeException('Unable to load user.');
    }

    public function findByPlatformId(string $platformUserId): ?array
    {
        $statement = $this->database->pdo()->prepare('SELECT * FROM users WHERE platform_user_id = ?');
        $statement->execute([$platformUserId]);
        $user = $statement->fetch();
        return $user === false ? null : $user;
    }

    public function setFlag(string $platformUserId, string $flag, bool $value): bool
    {
        if (!in_array($flag, ['is_banned', 'is_muted'], true)) {
            throw new \InvalidArgumentException('Unsupported user flag.');
        }
        $statement = $this->database->pdo()->prepare("UPDATE users SET {$flag} = ? WHERE platform_user_id = ?");
        $statement->execute([(int) $value, $platformUserId]);
        return $this->findByPlatformId($platformUserId) !== null;
    }

    public function checkAndTouchCooldown(int $userId, int $delaySeconds): int
    {
        if ($delaySeconds <= 0) {
            return 0;
        }

        return $this->database->transaction(function (PDO $pdo) use ($userId, $delaySeconds): int {
            $statement = $pdo->prepare('SELECT last_action_at FROM users WHERE id = ? FOR UPDATE');
            $statement->execute([$userId]);
            $lastAction = $statement->fetchColumn();
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            if (is_string($lastAction) && $lastAction !== '') {
                $last = new DateTimeImmutable($lastAction, new DateTimeZone('UTC'));
                $remaining = $delaySeconds - ($now->getTimestamp() - $last->getTimestamp());
                if ($remaining > 0) {
                    return $remaining;
                }
            }

            $update = $pdo->prepare('UPDATE users SET last_action_at = ? WHERE id = ?');
            $update->execute([$now->format('Y-m-d H:i:s'), $userId]);
            return 0;
        });
    }

    public function setEncryptedValue(int $userId, string $column, string $cipher): void
    {
        if (!in_array($column, ['merchant_key_cipher', 'otp_secret_cipher'], true)) {
            throw new \InvalidArgumentException('Unsupported encrypted field.');
        }
        $statement = $this->database->pdo()->prepare("UPDATE users SET {$column} = ? WHERE id = ?");
        $statement->execute([$cipher, $userId]);
    }

    public function recordCommand(int $userId, string $command, bool $succeeded): void
    {
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO command_usage (user_id, command, succeeded) VALUES (?, ?, ?)'
        );
        $statement->execute([$userId, substr($command, 0, 64), (int) $succeeded]);
    }

    public function userStats(int $userId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT COUNT(*) total,
                    COALESCE(SUM(succeeded = 1), 0) succeeded,
                    COALESCE(SUM(succeeded = 0), 0) failed,
                    MAX(created_at) last_used_at
             FROM command_usage WHERE user_id = ?'
        );
        $statement->execute([$userId]);
        return $statement->fetch() ?: ['total' => 0, 'succeeded' => 0, 'failed' => 0, 'last_used_at' => null];
    }

    public function globalStats(): array
    {
        $statement = $this->database->pdo()->query(
            'SELECT (SELECT COUNT(*) FROM users) users,
                    COUNT(*) total,
                    COALESCE(SUM(succeeded = 1), 0) succeeded,
                    COALESCE(SUM(succeeded = 0), 0) failed
             FROM command_usage'
        );
        return $statement->fetch() ?: ['users' => 0, 'total' => 0, 'succeeded' => 0, 'failed' => 0];
    }

    public function claimWebhookEvent(string $provider, string $eventId, string $eventType): array
    {
        $claimToken = bin2hex(random_bytes(16));
        $statement = $this->database->pdo()->prepare(
            'INSERT IGNORE INTO webhook_events (provider, event_id, event_type, claim_token) VALUES (?, ?, ?, ?)'
        );
        $statement->execute([$provider, $eventId, $eventType, $claimToken]);
        if ($statement->rowCount() === 1) {
            return ['state' => 'processing', 'claim_token' => $claimToken];
        }
        $expire = $this->database->pdo()->prepare(
            "UPDATE webhook_events SET state = 'failed', claim_token = NULL
             WHERE provider = ? AND event_id = ? AND state = 'processing'
             AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)"
        );
        $expire->execute([$provider, $eventId]);
        $retry = $this->database->pdo()->prepare(
            "UPDATE webhook_events SET state = 'processing', claim_token = ?
             WHERE provider = ? AND event_id = ? AND state = 'failed'"
        );
        $retry->execute([$claimToken, $provider, $eventId]);
        if ($retry->rowCount() === 1) {
            return ['state' => 'processing', 'claim_token' => $claimToken];
        }
        $select = $this->database->pdo()->prepare(
            'SELECT state FROM webhook_events WHERE provider = ? AND event_id = ?'
        );
        $select->execute([$provider, $eventId]);
        return ['state' => (string) $select->fetchColumn(), 'claim_token' => null];
    }

    public function finishWebhookEvent(string $provider, string $eventId, string $claimToken, bool $succeeded): void
    {
        $state = $succeeded ? 'completed' : 'failed';
        $statement = $this->database->pdo()->prepare(
            "UPDATE webhook_events SET state = ?, claim_token = NULL
             WHERE provider = ? AND event_id = ? AND state = 'processing' AND claim_token = ?"
        );
        $statement->execute([$state, $provider, $eventId, $claimToken]);
    }

    public function claimTelegramUpdate(string $updateId): array
    {
        $claimToken = bin2hex(random_bytes(16));
        $statement = $this->database->pdo()->prepare(
            'INSERT IGNORE INTO telegram_updates (update_id, claim_token) VALUES (?, ?)'
        );
        $statement->execute([$updateId, $claimToken]);
        if ($statement->rowCount() === 1) {
            return ['state' => 'processing', 'is_new' => true, 'response_text' => null, 'claim_token' => $claimToken];
        }

        $select = $this->database->pdo()->prepare(
            'SELECT state, response_text, claim_token FROM telegram_updates WHERE update_id = ?'
        );
        $select->execute([$updateId]);
        $update = $select->fetch();
        if ($update === false) {
            throw new \RuntimeException('Telegram update claim could not be loaded.');
        }
        if ($update['state'] === 'command_completed') {
            $deliveryToken = bin2hex(random_bytes(16));
            $delivery = $this->database->pdo()->prepare(
                "UPDATE telegram_updates SET state = 'delivery_claimed', claim_token = ?
                 WHERE update_id = ? AND state = 'command_completed'"
            );
            $delivery->execute([$deliveryToken, $updateId]);
            if ($delivery->rowCount() === 1) {
                return [
                    'state' => 'delivery_claimed',
                    'is_new' => false,
                    'owns_delivery' => true,
                    'response_text' => $update['response_text'],
                    'claim_token' => $deliveryToken,
                ];
            }
            return $this->claimTelegramUpdate($updateId);
        }
        if ($update['state'] === 'delivery_claimed') {
            $release = $this->database->pdo()->prepare(
                "UPDATE telegram_updates SET state = 'command_completed', claim_token = NULL
                 WHERE update_id = ? AND state = 'delivery_claimed'
                 AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)"
            );
            $release->execute([$updateId]);
            if ($release->rowCount() === 1) {
                return $this->claimTelegramUpdate($updateId);
            }
        }
        $update['is_new'] = false;
        $update['owns_delivery'] = false;
        return $update;
    }

    public function saveTelegramResponse(string $updateId, string $claimToken, string $response): void
    {
        $statement = $this->database->pdo()->prepare(
            "UPDATE telegram_updates SET state = 'delivery_claimed', response_text = ?
             WHERE update_id = ? AND state = 'processing' AND claim_token = ?"
        );
        $statement->execute([$response, $updateId, $claimToken]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Telegram update changed while it was being processed.');
        }
    }

    public function markTelegramUpdateCompleted(string $updateId, string $claimToken): void
    {
        $statement = $this->database->pdo()->prepare(
            "UPDATE telegram_updates SET state = 'completed', claim_token = NULL
             WHERE update_id = ? AND state = 'delivery_claimed' AND claim_token = ?"
        );
        $statement->execute([$updateId, $claimToken]);
    }

    public function releaseTelegramDelivery(string $updateId, string $claimToken): void
    {
        $statement = $this->database->pdo()->prepare(
            "UPDATE telegram_updates SET state = 'command_completed', claim_token = NULL
             WHERE update_id = ? AND state = 'delivery_claimed' AND claim_token = ?"
        );
        $statement->execute([$updateId, $claimToken]);
    }
}
