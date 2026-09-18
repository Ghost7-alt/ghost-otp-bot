<?php

declare(strict_types=1);

namespace GhostBot;

final class StripeSandbox
{
    public function testPaymentMethod(string $secretKey, string $paymentMethod): array
    {
        if (!str_starts_with($secretKey, 'sk_test_')) {
            throw new \InvalidArgumentException('Only Stripe test-mode secret keys are accepted.');
        }
        if (!preg_match('/^pm_[A-Za-z0-9_]{3,100}$/', $paymentMethod)) {
            throw new \InvalidArgumentException('Use a Stripe test PaymentMethod ID such as pm_card_visa.');
        }

        [$status, $data] = $this->request($secretKey, '/payment_intents', [
            'amount' => Config::int('STRIPE_TEST_AMOUNT', 50),
            'currency' => Config::get('STRIPE_TEST_CURRENCY', 'usd'),
            'payment_method' => $paymentMethod,
            'automatic_payment_methods[enabled]' => 'true',
            'automatic_payment_methods[allow_redirects]' => 'never',
            'metadata[source]' => 'ghost-otp-bot-sandbox',
        ]);
        if ($status < 400 && ($data['status'] ?? '') === 'requires_confirmation' && isset($data['id'])) {
            [$status, $data] = $this->request(
                $secretKey,
                '/payment_intents/' . rawurlencode((string) $data['id']) . '/confirm',
                []
            );
        }
        if ($status >= 400) {
            return [
                'ok' => false,
                'status' => $data['error']['decline_code'] ?? $data['error']['code'] ?? 'rejected',
                'message' => $data['error']['message'] ?? 'Test payment was rejected.',
            ];
        }
        $paymentStatus = $data['status'] ?? 'unknown';
        $succeeded = $paymentStatus === 'succeeded';
        return [
            'ok' => $succeeded,
            'status' => $paymentStatus,
            'intent' => $data['id'] ?? null,
            'message' => $succeeded
                ? 'Stripe test-mode payment completed.'
                : 'Stripe test-mode payment did not complete.',
        ];
    }

    private function request(string $secretKey, string $path, array $fields): array
    {
        $baseUrl = rtrim(Config::get('STRIPE_API_BASE', 'https://api.stripe.com/v1') ?? '', '/');
        $handle = curl_init($baseUrl . $path);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_USERPWD => $secretKey . ':',
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $transportError = curl_error($handle);
        curl_close($handle);
        if ($body === false) {
            throw new \RuntimeException('Stripe request failed: ' . $transportError);
        }

        $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        return [$status, $data];
    }
}
