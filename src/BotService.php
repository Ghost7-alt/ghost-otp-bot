<?php

declare(strict_types=1);

namespace GhostBot;

final class BotService
{
    private const COOLDOWN_COMMANDS = [
        'otpnew', 'otpverify', 'merchant-add', 'merchant-check', 'newapi', 'bin', 'iban',
    ];

    public function __construct(
        private readonly UserRepository $users,
        private readonly ApiTokenService $apiTokens,
        private readonly Crypto $crypto,
        private readonly StripeSandbox $stripe,
        private readonly BinLookup $binLookup,
        private readonly string $adminId,
        private readonly int $antiSpamSeconds
    ) {
    }

    public function handle(string $platformUserId, ?string $username, string $text): string
    {
        try {
            $user = $this->users->upsert($platformUserId, $username);
            $isAdmin = hash_equals($this->adminId, $platformUserId);
            if ((bool) $user['is_banned'] && !$isAdmin) {
                return 'Access denied: you are banned.';
            }
            if ((bool) $user['is_muted'] && !$isAdmin) {
                return 'You are muted. Contact the administrator.';
            }
            try {
                [$command, $arguments] = $this->parse($text);
            } catch (\InvalidArgumentException $exception) {
                try {
                    $this->users->recordCommand((int) $user['id'], 'invalid_input', false);
                } catch (\Throwable $recordException) {
                    error_log($this->exceptionSummary($recordException));
                }
                return 'Invalid request: ' . $exception->getMessage();
            }

            if (!$isAdmin && in_array($command, self::COOLDOWN_COMMANDS, true)) {
                $remaining = $this->users->checkAndTouchCooldown((int) $user['id'], $this->antiSpamSeconds);
                if ($remaining > 0) {
                    return "Please wait {$remaining}s before using another action.";
                }
            }
        } catch (\Throwable $exception) {
            error_log($this->exceptionSummary($exception));
            return 'The command could not be completed. Please try again later.';
        }

        try {
            $response = $this->dispatch($command, $arguments, $user, $isAdmin);
            $succeeded = true;
        } catch (\InvalidArgumentException $exception) {
            $response = 'Invalid request: ' . $exception->getMessage();
            $succeeded = false;
        } catch (\Throwable $exception) {
            error_log($this->exceptionSummary($exception));
            $response = 'The command could not be completed. Please try again later.';
            $succeeded = false;
        }

        try {
            $this->users->recordCommand((int) $user['id'], $command, $succeeded);
        } catch (\Throwable $exception) {
            error_log('Could not record command usage: ' . $this->exceptionSummary($exception));
        }
        return $response;
    }

    private function exceptionSummary(\Throwable $exception): string
    {
        return sprintf(
            '%s at %s:%d: %s',
            $exception::class,
            $exception->getFile(),
            $exception->getLine(),
            $exception->getMessage()
        );
    }

    private function dispatch(string $command, string $arguments, array $user, bool $isAdmin): string
    {
        return match ($command) {
            'start', 'help', 'panel' => $this->help($isAdmin),
            'me' => $this->profile($user, $isAdmin),
            'stats' => $this->formatStats('Your stats', $this->users->userStats((int) $user['id'])),
            'globalstats' => $this->formatStats('Global stats', $this->users->globalStats()),
            'otpnew' => $this->newOtp($user),
            'otpverify' => $this->verifyOtp($user, $arguments),
            'merchant-add' => $this->saveMerchantKey($user, $arguments),
            'merchant-key' => $this->merchantKey($user),
            'merchant-check' => $this->merchantCheck($user, $arguments),
            'check', 'cc' => 'Live card-number checking is not supported. Use /merchant-check with a Stripe test PaymentMethod ID.',
            'newapi' => $this->newApiToken($user, $arguments, (string) $user['platform_user_id']),
            'myapi' => $this->listApiTokens($user),
            'key' => $this->lookupApiToken($user, $arguments),
            'bin' => $this->formatBin($this->binLookup->lookup($arguments)),
            'iban' => $this->formatIban(Utilities::validateIban($arguments)),
            'ban', 'unban', 'mute', 'unmute' => $this->adminFlag($command, $arguments, $isAdmin),
            'userstats' => $this->adminUserStats($arguments, $isAdmin),
            'adminapi' => $this->adminApiToken($arguments, $isAdmin),
            'revokeapi' => $this->adminRevokeApi($arguments, $isAdmin),
            default => throw new \InvalidArgumentException('Unknown command. Use /help.'),
        };
    }

    private function parse(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new \InvalidArgumentException('Command cannot be empty.');
        }
        [$command, $arguments] = array_pad(preg_split('/\s+/', $text, 2) ?: [], 2, '');
        $command = strtolower(ltrim(explode('@', $command, 2)[0], '/'));
        return [$command, trim($arguments)];
    }

    private function help(bool $isAdmin): string
    {
        $text = "Ghost OTP Bot\n"
            . "/me - profile\n/stats - your usage\n/globalstats - bot usage\n"
            . "/otpnew and /otpverify CODE - TOTP tools\n"
            . "/merchant-add sk_test_... - save encrypted Stripe test key\n"
            . "/merchant-key - show masked saved key\n"
            . "/merchant-check pm_card_visa - run a sandbox test payment\n"
            . "/newapi LABEL, /myapi, /key PREFIX - API token tools\n"
            . "/bin 424242, /iban IBAN - utilities";
        if ($isAdmin) {
            $text .= "\n\nAdmin\n/ban ID, /unban ID, /mute ID, /unmute ID\n"
                . "/userstats ID\n/adminapi ID LABEL\n/revokeapi TOKEN_ID";
        }
        return $text;
    }

    private function profile(array $user, bool $isAdmin): string
    {
        return "User ID: {$user['platform_user_id']}\n"
            . 'Username: ' . ($user['username'] ?: 'not set') . "\n"
            . 'Role: ' . ($isAdmin ? 'admin' : 'user') . "\n"
            . 'Merchant mode: ' . ($user['merchant_key_cipher'] ? 'configured' : 'not configured') . "\n"
            . 'Joined: ' . $user['created_at'];
    }

    private function formatStats(string $title, array $stats): string
    {
        $parts = [$title];
        if (array_key_exists('users', $stats)) {
            $parts[] = 'Users: ' . $stats['users'];
        }
        $parts[] = 'Commands: ' . $stats['total'];
        $parts[] = 'Succeeded: ' . $stats['succeeded'];
        $parts[] = 'Failed: ' . $stats['failed'];
        if (!empty($stats['last_used_at'])) {
            $parts[] = 'Last used: ' . $stats['last_used_at'] . ' UTC';
        }
        return implode("\n", $parts);
    }

    private function newOtp(array $user): string
    {
        $secret = Totp::generateSecret();
        $this->users->setEncryptedValue((int) $user['id'], 'otp_secret_cipher', $this->crypto->encrypt($secret));
        $issuer = rawurlencode(Config::get('OTP_ISSUER', 'Ghost OTP Bot') ?? 'Ghost OTP Bot');
        $account = rawurlencode((string) ($user['username'] ?: $user['platform_user_id']));
        return "New OTP secret: {$secret}\nProvisioning URI: otpauth://totp/{$issuer}:{$account}?secret={$secret}&issuer={$issuer}";
    }

    private function verifyOtp(array $user, string $token): string
    {
        if (!$user['otp_secret_cipher']) {
            throw new \InvalidArgumentException('Create an OTP secret first with /otpnew.');
        }
        $valid = Totp::verify($this->crypto->decrypt((string) $user['otp_secret_cipher']), $token);
        return $valid ? 'OTP is valid.' : 'OTP is invalid.';
    }

    private function saveMerchantKey(array $user, string $key): string
    {
        if (!preg_match('/^sk_test_[A-Za-z0-9_]{10,200}$/', $key)) {
            throw new \InvalidArgumentException('Only a Stripe sk_test_ key is accepted.');
        }
        $this->users->setEncryptedValue(
            (int) $user['id'],
            'merchant_key_cipher',
            $this->crypto->encrypt($key)
        );
        return 'Stripe test key saved encrypted. The command message was deleted where platform permissions allowed.';
    }

    private function merchantKey(array $user): string
    {
        if (!$user['merchant_key_cipher']) {
            return 'No Stripe test key is saved.';
        }
        return 'Saved key: ' . Crypto::mask($this->crypto->decrypt((string) $user['merchant_key_cipher']));
    }

    private function merchantCheck(array $user, string $paymentMethod): string
    {
        if (!$user['merchant_key_cipher']) {
            throw new \InvalidArgumentException('Add a Stripe test key with /merchant-add first.');
        }
        $result = $this->stripe->testPaymentMethod(
            $this->crypto->decrypt((string) $user['merchant_key_cipher']),
            $paymentMethod
        );
        return 'Sandbox result: ' . ($result['ok'] ? 'success' : 'not successful')
            . "\nStatus: {$result['status']}\n{$result['message']}";
    }

    private function newApiToken(array $user, string $label, string $createdBy): string
    {
        $token = $this->apiTokens->generate((int) $user['id'], $label, $createdBy);
        return "API token (shown once): {$token}\nStore it securely. Only its hash is retained.";
    }

    private function listApiTokens(array $user): string
    {
        $tokens = $this->apiTokens->listForUser((int) $user['id']);
        if ($tokens === []) {
            return 'You have no API tokens.';
        }
        $lines = ['Your API tokens'];
        foreach ($tokens as $token) {
            $state = $token['revoked_at'] ? 'revoked' : 'active';
            $lines[] = "{$token['token_prefix']}… | {$token['label']} | {$state}";
        }
        return implode("\n", $lines);
    }

    private function lookupApiToken(array $user, string $prefix): string
    {
        $token = $this->apiTokens->lookup($prefix, (int) $user['id']);
        if ($token === null) {
            return 'No API token with that exact prefix belongs to you.';
        }
        return "Token ID: {$token['id']}\nLabel: {$token['label']}\nCreated: {$token['created_at']}\n"
            . 'State: ' . ($token['revoked_at'] ? 'revoked' : 'active');
    }

    private function formatBin(array $bin): string
    {
        if (isset($bin['message'])) {
            return "BIN: {$bin['bin']}\n{$bin['message']}";
        }
        return "BIN: {$bin['bin']}\nScheme: {$bin['scheme']}\nType: {$bin['type']}\n"
            . "Brand: {$bin['brand']}\nBank: {$bin['bank']}\nCountry: {$bin['country']}";
    }

    private function formatIban(array $iban): string
    {
        return 'IBAN: ' . ($iban['valid'] ? 'valid' : 'invalid')
            . "\nCountry: {$iban['country']}\n{$iban['reason']}";
    }

    private function adminFlag(string $command, string $targetId, bool $isAdmin): string
    {
        $this->assertAdmin($isAdmin);
        if ($targetId === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $targetId)) {
            throw new \InvalidArgumentException("Usage: /{$command} USER_ID");
        }
        if (hash_equals($this->adminId, $targetId)) {
            throw new \InvalidArgumentException('The configured administrator cannot be restricted.');
        }
        $flag = in_array($command, ['ban', 'unban'], true) ? 'is_banned' : 'is_muted';
        $value = in_array($command, ['ban', 'mute'], true);
        return $this->users->setFlag($targetId, $flag, $value)
            ? ucfirst($command) . " applied to {$targetId}."
            : "User {$targetId} was not found.";
    }

    private function adminUserStats(string $targetId, bool $isAdmin): string
    {
        $this->assertAdmin($isAdmin);
        $target = $this->users->findByPlatformId($targetId);
        if ($target === null) {
            return "User {$targetId} was not found.";
        }
        return $this->formatStats("Stats for {$targetId}", $this->users->userStats((int) $target['id']));
    }

    private function adminApiToken(string $arguments, bool $isAdmin): string
    {
        $this->assertAdmin($isAdmin);
        [$targetId, $label] = array_pad(preg_split('/\s+/', trim($arguments), 2) ?: [], 2, '');
        $target = $this->users->findByPlatformId($targetId);
        if ($target === null) {
            throw new \InvalidArgumentException('Usage: /adminapi EXISTING_USER_ID LABEL');
        }
        return $this->newApiToken($target, $label, $this->adminId);
    }

    private function adminRevokeApi(string $tokenId, bool $isAdmin): string
    {
        $this->assertAdmin($isAdmin);
        if (!ctype_digit($tokenId)) {
            throw new \InvalidArgumentException('Usage: /revokeapi TOKEN_ID');
        }
        return $this->apiTokens->revoke((int) $tokenId) ? 'API token revoked.' : 'Active token not found.';
    }

    private function assertAdmin(bool $isAdmin): void
    {
        if (!$isAdmin) {
            throw new \InvalidArgumentException('Administrator access required.');
        }
    }
}
