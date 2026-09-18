<?php

declare(strict_types=1);

namespace GhostBot;

final class Application
{
    private Database $database;
    private UserRepository $users;
    private ApiTokenService $apiTokens;
    private TelegramClient $telegram;
    private BotService $bot;

    public function __construct()
    {
        $this->database = new Database();
        $this->users = new UserRepository($this->database);
        $this->apiTokens = new ApiTokenService($this->database->pdo());
        $this->telegram = new TelegramClient(Config::require('TELEGRAM_BOT_TOKEN'));
        $this->bot = new BotService(
            $this->users,
            $this->apiTokens,
            new Crypto(Config::require('APP_KEY')),
            new StripeSandbox(),
            new BinLookup(),
            new NvidiaClient(),
            Config::require('ADMIN_ID'),
            Config::int('ANTI_SPAM_SECONDS', 5)
        );
    }

    public function handle(string $method, string $path, string $body, array $headers): array
    {
        if ($method === 'GET' && $path === '/health') {
            $this->database->pdo()->query('SELECT 1');
            return $this->json(200, ['ok' => true]);
        }
        if ($method === 'POST' && $path === '/webhook/telegram') {
            return $this->telegramWebhook($body, $headers);
        }
        if ($method === 'POST' && $path === '/webhook/call') {
            return $this->callWebhook($body, $headers);
        }
        if (str_starts_with($path, '/api/v1/')) {
            return $this->api($method, $path, $body, $headers);
        }
        return $this->json(404, ['error' => 'Not found']);
    }

    private function telegramWebhook(string $body, array $headers): array
    {
        $expected = Config::require('TELEGRAM_WEBHOOK_SECRET');
        $provided = $headers['x-telegram-bot-api-secret-token'] ?? '';
        if (!hash_equals($expected, $provided)) {
            return $this->json(401, ['error' => 'Unauthorized']);
        }

        $update = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        $message = $update['message'] ?? null;
        if (!is_array($message) || !isset($message['text'], $message['chat']['id'], $message['from']['id'])) {
            return $this->json(200, ['ok' => true, 'ignored' => true]);
        }
        $updateId = (string) ($update['update_id'] ?? '');
        if ($updateId === '' || !ctype_digit($updateId)) {
            return $this->json(422, ['error' => 'Telegram update_id is required']);
        }
        $claimed = $this->users->claimTelegramUpdate($updateId);
        if (!$claimed['is_new'] && $claimed['state'] === 'completed') {
            return $this->json(200, ['ok' => true, 'duplicate' => true]);
        }
        if (!$claimed['is_new'] && $claimed['state'] === 'processing') {
            return $this->json(503, ['error' => 'Telegram update is still being processed; retry delivery.']);
        }
        if (!$claimed['is_new'] && $claimed['state'] === 'failed') {
            return $this->json(503, ['error' => 'Telegram command requires administrator recovery.']);
        }
        if (!$claimed['is_new'] && $claimed['state'] === 'delivery_claimed' && !$claimed['owns_delivery']) {
            return $this->json(503, ['error' => 'Telegram response delivery is already in progress.']);
        }

        $chatId = (string) $message['chat']['id'];
        $userId = (string) $message['from']['id'];
        $username = isset($message['from']['username']) ? (string) $message['from']['username'] : null;
        $text = (string) $message['text'];
        if ($claimed['is_new']) {
            if ($this->isSensitiveCommand($text) && ($message['chat']['type'] ?? '') !== 'private') {
                $response = 'For security, this command may only be used in a private chat with the bot.';
            } else {
                $response = $this->bot->handle($userId, $username, $text);
            }
        } else {
            $response = (string) $claimed['response_text'];
        }

        if ($claimed['is_new'] && preg_match('/^\s*\/?merchant-add(?:@\S+)?\s+/i', $text) && isset($message['message_id'])) {
            try {
                $this->telegram->deleteMessage($chatId, (int) $message['message_id']);
            } catch (\Throwable $exception) {
                error_log('Could not delete merchant key message: ' . $exception->getMessage());
                $response .= "\nSecurity warning: delete the message containing your Stripe key and rotate that key before continuing.";
            }
        }
        if ($claimed['is_new']) {
            $this->users->saveTelegramResponse($updateId, (string) $claimed['claim_token'], $response);
        }
        try {
            $this->telegram->sendMessage($chatId, $response);
        } catch (\Throwable $exception) {
            $this->users->releaseTelegramDelivery($updateId, (string) $claimed['claim_token']);
            throw $exception;
        }
        if ($claimed['is_new']) {
            $this->sendAuditLog($userId, $text);
        }
        $this->users->markTelegramUpdateCompleted($updateId, (string) $claimed['claim_token']);
        return $this->json(200, ['ok' => true]);
    }

    private function isSensitiveCommand(string $text): bool
    {
        $parts = preg_split('/\s+/', trim($text), 2) ?: [];
        $command = strtolower(ltrim(explode('@', $parts[0] ?? '', 2)[0], '/'));
        return in_array($command, ['otpnew', 'otpverify', 'merchant-add', 'newapi', 'adminapi'], true);
    }

    private function callWebhook(string $body, array $headers): array
    {
        $provided = $headers['x-webhook-secret'] ?? '';
        if (!hash_equals(Config::require('CALL_WEBHOOK_SECRET'), $provided)) {
            return $this->json(401, ['error' => 'Unauthorized']);
        }
        $event = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        $rawEventId = $event['id'] ?? null;
        $rawEventType = $event['type'] ?? null;
        if ((!is_string($rawEventId) && !is_int($rawEventId)) || !is_string($rawEventType)) {
            return $this->json(422, ['error' => 'id and type must be scalar strings']);
        }
        $eventId = (string) $rawEventId;
        $eventType = $rawEventType;
        if ($eventId === '' || $eventType === '' || strlen($eventId) > 128 || strlen($eventType) > 80) {
            return $this->json(422, ['error' => 'id and type are required']);
        }

        $claim = $this->users->claimWebhookEvent('call', $eventId, $eventType);
        if ($claim['state'] === 'processing' && $claim['claim_token'] === null) {
            return $this->json(503, ['error' => 'Call event is already being processed.']);
        }
        if ($claim['claim_token'] !== null) {
            $claimToken = (string) $claim['claim_token'];
            try {
                $this->sendLogMessage("Call webhook event: {$eventType}", true);
                $this->users->finishWebhookEvent('call', $eventId, $claimToken, true);
            } catch (\Throwable $exception) {
                $this->users->finishWebhookEvent('call', $eventId, $claimToken, false);
                throw $exception;
            }
        }
        return $this->json(200, ['ok' => true, 'duplicate' => $claim['state'] === 'completed']);
    }

    private function api(string $method, string $path, string $body, array $headers): array
    {
        $authorization = $headers['authorization'] ?? '';
        if (!preg_match('/^(?i:Bearer)\s+(ghost_[A-Fa-f0-9]{48})$/', $authorization, $matches)) {
            return $this->json(401, ['error' => 'Valid Bearer token required']);
        }
        $user = $this->apiTokens->authenticate($matches[1]);
        if ($user === null) {
            return $this->json(401, ['error' => 'Invalid or revoked token']);
        }

        if ($method === 'GET' && $path === '/api/v1/me') {
            return $this->json(200, [
                'user_id' => $user['platform_user_id'],
                'username' => $user['username'],
                'stats' => $this->users->userStats((int) $user['id']),
            ]);
        }
        if ($method === 'GET' && $path === '/api/v1/bin') {
            try {
                return $this->json(200, (new BinLookup())->lookup((string) ($_GET['value'] ?? '')));
            } catch (\InvalidArgumentException $exception) {
                return $this->json(422, ['error' => $exception->getMessage()]);
            }
        }
        if ($method === 'POST' && $path === '/api/v1/iban') {
            $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            return $this->json(200, Utilities::validateIban((string) ($payload['iban'] ?? '')));
        }
        return $this->json(404, ['error' => 'API route not found']);
    }

    private function sendAuditLog(string $userId, string $text): void
    {
        $parts = preg_split('/\s+/', trim($text), 2) ?: [];
        $command = strtolower(ltrim(explode('@', $parts[0] ?? '', 2)[0], '/'));
        try {
            $this->sendLogMessage("Command /{$command} used by {$userId}");
        } catch (\Throwable $exception) {
            error_log('Could not send audit log: ' . $exception->getMessage());
        }
    }

    private function sendLogMessage(string $message, bool $required = false): void
    {
        $chatId = Config::get('LOGS_CHAT_ID');
        if ($chatId === null || $chatId === '') {
            if ($required) {
                throw new \RuntimeException('LOGS_CHAT_ID is required for call-event delivery.');
            }
            return;
        }
        $this->telegram->sendMessage($chatId, $message);
    }

    private function json(int $status, array $payload): array
    {
        return [$status, ['Content-Type' => 'application/json; charset=utf-8'], json_encode($payload, JSON_THROW_ON_ERROR)];
    }
}
