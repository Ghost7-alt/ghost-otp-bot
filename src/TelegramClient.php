<?php

declare(strict_types=1);

namespace GhostBot;

final class TelegramClient
{
    private string $baseUrl;

    public function __construct(private readonly string $token)
    {
        $this->baseUrl = rtrim(Config::get('TELEGRAM_API_BASE', 'https://api.telegram.org') ?? '', '/');
    }

    public function sendMessage(string $chatId, string $text): void
    {
        $this->call('sendMessage', ['chat_id' => $chatId, 'text' => $text]);
    }

    public function deleteMessage(string $chatId, int $messageId): void
    {
        $this->call('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
    }

    private function call(string $method, array $payload): void
    {
        $handle = curl_init($this->baseUrl . '/bot' . $this->token . '/' . $method);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_TIMEOUT => 10,
        ]);
        $body = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        $response = is_string($body) ? json_decode($body, true) : null;
        if ($body === false || $status < 200 || $status >= 300 || !is_array($response) || ($response['ok'] ?? false) !== true) {
            throw new \RuntimeException('Messaging API request failed' . ($error !== '' ? ': ' . $error : '.'));
        }
    }
}
