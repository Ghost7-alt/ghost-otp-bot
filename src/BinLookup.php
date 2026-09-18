<?php

declare(strict_types=1);

namespace GhostBot;

final class BinLookup
{
    public function lookup(string $input): array
    {
        $bin = Utilities::normalizeBin($input);
        $template = Config::get('BIN_LOOKUP_URL');
        if ($template === null || $template === '') {
            return ['bin' => $bin, 'message' => 'BIN format is valid; remote lookup is not configured.'];
        }
        if (!str_contains($template, '{bin}')) {
            throw new \RuntimeException('BIN_LOOKUP_URL must contain the {bin} placeholder.');
        }

        $url = str_replace('{bin}', rawurlencode($bin), $template);
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($body === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException('BIN provider unavailable' . ($error !== '' ? ': ' . $error : '.'));
        }
        try {
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('BIN provider returned invalid JSON.', 0, $exception);
        }
        return [
            'bin' => $bin,
            'scheme' => $data['scheme'] ?? 'unknown',
            'type' => $data['type'] ?? 'unknown',
            'brand' => $data['brand'] ?? 'unknown',
            'bank' => $data['bank']['name'] ?? 'unknown',
            'country' => $data['country']['name'] ?? 'unknown',
        ];
    }
}
