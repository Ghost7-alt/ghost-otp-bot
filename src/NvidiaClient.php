<?php

declare(strict_types=1);

namespace GhostBot;

final class NvidiaClient
{
    private const DEFAULT_ENDPOINT = 'https://integrate.api.nvidia.com/v1/chat/completions';
    private const DEFAULT_MODEL = 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning';
    private const MAX_RESPONSE_BYTES = 1048576;

    private readonly ?string $apiKey;
    private readonly string $endpoint;
    private readonly string $model;
    private readonly int $timeout;
    private readonly int $maxTokens;
    private readonly int $reasoningBudget;
    private readonly ?\Closure $transport;

    public function __construct(
        ?string $apiKey = null,
        ?string $endpoint = null,
        ?string $model = null,
        ?int $timeout = null,
        ?int $maxTokens = null,
        ?int $reasoningBudget = null,
        ?callable $transport = null
    ) {
        $this->apiKey = $apiKey ?? Config::get('NVIDIA_API_KEY');
        $this->endpoint = $endpoint ?? Config::get('NVIDIA_API_ENDPOINT', self::DEFAULT_ENDPOINT) ?? '';
        $this->model = $model ?? Config::get('NVIDIA_MODEL', self::DEFAULT_MODEL) ?? '';
        $this->timeout = $timeout ?? Config::int('NVIDIA_TIMEOUT', 60);
        $this->maxTokens = $maxTokens ?? Config::int('NVIDIA_MAX_TOKENS', 65536);
        $this->reasoningBudget = $reasoningBudget ?? Config::int('NVIDIA_REASONING_BUDGET', 16384);
        $this->transport = $transport === null ? null : \Closure::fromCallable($transport);

        $scheme = strtolower((string) parse_url($this->endpoint, PHP_URL_SCHEME));
        if ($scheme !== 'https' || filter_var($this->endpoint, FILTER_VALIDATE_URL) === false) {
            throw new \RuntimeException('NVIDIA_API_ENDPOINT must be a valid HTTPS URL.');
        }
        if ($this->model === '' || strlen($this->model) > 200) {
            throw new \RuntimeException('NVIDIA_MODEL must be 1-200 characters.');
        }
        if ($this->timeout < 1 || $this->timeout > 120) {
            throw new \RuntimeException('NVIDIA_TIMEOUT must be between 1 and 120 seconds.');
        }
        if ($this->maxTokens < 1 || $this->maxTokens > 65536) {
            throw new \RuntimeException('NVIDIA_MAX_TOKENS must be between 1 and 65536.');
        }
        if ($this->reasoningBudget < 0 || $this->reasoningBudget > $this->maxTokens) {
            throw new \RuntimeException('NVIDIA_REASONING_BUDGET must be between 0 and NVIDIA_MAX_TOKENS.');
        }
    }

    public function chat(string $prompt): string
    {
        $prompt = trim($prompt);
        $promptCharacters = preg_match_all('/./us', $prompt);
        if ($prompt === '' || $promptCharacters === false || $promptCharacters > 8000) {
            throw new \InvalidArgumentException('Chat prompt must be 1-8000 characters.');
        }
        if ($this->apiKey === null || $this->apiKey === '') {
            throw new \RuntimeException('NVIDIA chat is not configured.');
        }

        $payload = [
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'reasoning_budget' => $this->reasoningBudget,
            'stream' => false,
            'temperature' => 0.6,
            'top_p' => 0.95,
        ];
        $result = $this->transport === null
            ? $this->request($payload)
            : ($this->transport)($this->endpoint, $this->apiKey, $payload, $this->timeout);
        if (!is_array($result) || !array_key_exists(0, $result) || !array_key_exists(1, $result)) {
            throw new \RuntimeException('NVIDIA transport returned an invalid result.');
        }
        [$status, $body] = $result;
        if (!is_int($status) || !is_string($body)) {
            throw new \RuntimeException('NVIDIA transport returned an invalid result.');
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("NVIDIA API request failed with HTTP {$status}.");
        }
        try {
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('NVIDIA API returned invalid JSON.', 0, $exception);
        }
        $content = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            throw new \RuntimeException('NVIDIA API returned an unexpected response.');
        }
        return $content;
    }

    private function request(array $payload): array
    {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $responseBody = '';
        $handle = curl_init($this->endpoint);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $encoded,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: application/json',
                'Content-Type: application/json',
            ],
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseBody): int {
                if (strlen($responseBody) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    return 0;
                }
                $responseBody .= $chunk;
                return strlen($chunk);
            },
        ]);
        $succeeded = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($succeeded === false) {
            throw new \RuntimeException('NVIDIA API is unavailable.');
        }
        return [(int) $status, $responseBody];
    }
}
