<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use GhostBot\Crypto;
use GhostBot\ApiTokenService;
use GhostBot\BinLookup;
use GhostBot\BotService;
use GhostBot\Database;
use GhostBot\NvidiaClient;
use GhostBot\StripeSandbox;
use GhostBot\Totp;
use GhostBot\UserRepository;
use GhostBot\Utilities;

$tests = [];

$tests['IBAN accepts a valid checksum'] = static function (): void {
    $result = Utilities::validateIban('GB82 WEST 1234 5698 7654 32');
    assertSame(true, $result['valid']);
    assertSame('GB', $result['country']);
};

$tests['IBAN rejects a bad checksum'] = static function (): void {
    $result = Utilities::validateIban('GB82 WEST 1234 5698 7654 33');
    assertSame(false, $result['valid']);
};

$tests['IBAN supports Honduras and Oman registry lengths'] = static function (): void {
    assertSame(true, Utilities::validateIban('HN70000000000000000000000000')['valid']);
    assertSame(true, Utilities::validateIban('OM100000000000000000000')['valid']);
};

$tests['BIN normalization is bounded'] = static function (): void {
    assertSame('424242', Utilities::normalizeBin('4242 42'));
    assertThrows(static fn () => Utilities::normalizeBin('12345'), InvalidArgumentException::class);
};

$tests['Integer configuration rejects invalid values'] = static function (): void {
    putenv('GHOST_TEST_INTEGER=not-a-number');
    assertThrows(static fn () => GhostBot\Config::int('GHOST_TEST_INTEGER', 5), RuntimeException::class);
    putenv('GHOST_TEST_INTEGER=12');
    assertSame(12, GhostBot\Config::int('GHOST_TEST_INTEGER', 5));
    putenv('GHOST_TEST_INTEGER');
};

$tests['API token labels count UTF-8 characters'] = static function (): void {
    $label = str_repeat('é', 80);
    assertSame(80, preg_match_all('/./us', $label));
    assertSame(81, preg_match_all('/./us', $label . 'é'));
};

$tests['TOTP matches an RFC 6238 vector truncated to six digits'] = static function (): void {
    $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
    assertSame('287082', Totp::current($secret, 59));
    assertSame(true, Totp::verify($secret, '287082', 0, 59));
    assertSame(false, Totp::verify($secret, '287083', 0, 59));
};

$tests['Encrypted secrets round trip and are masked'] = static function (): void {
    $crypto = new Crypto(base64_encode(str_repeat('k', 32)));
    $secret = 'sk_test_1234567890abcdef';
    $encrypted = $crypto->encrypt($secret);
    assertSame($secret, $crypto->decrypt($encrypted));
    assertSame(str_repeat('*', strlen($secret)), Crypto::mask($secret));
    assertSame('************', Crypto::mask('short-secret'));
};

$tests['NVIDIA chat sends the configured model request without exposing its key'] = static function (): void {
    $captured = null;
    $client = new NvidiaClient(
        'local-test-key',
        'https://nvidia.invalid/v1/chat/completions',
        'test/model',
        12,
        200,
        50,
        static function (string $endpoint, string $key, array $payload, int $timeout) use (&$captured): array {
            $captured = compact('endpoint', 'key', 'payload', 'timeout');
            return [200, '{"choices":[{"message":{"content":"TOTP uses time windows."}}]}'];
        }
    );

    assertSame('TOTP uses time windows.', $client->chat('Explain TOTP'));
    assertSame('https://nvidia.invalid/v1/chat/completions', $captured['endpoint']);
    assertSame('local-test-key', $captured['key']);
    assertSame('test/model', $captured['payload']['model']);
    assertSame('Explain TOTP', $captured['payload']['messages'][0]['content']);
    assertSame(12, $captured['timeout']);
};

$tests['NVIDIA chat validates prompts and configuration'] = static function (): void {
    $client = new NvidiaClient('', 'https://nvidia.invalid/v1/chat/completions');
    assertThrows(static fn () => $client->chat(''), InvalidArgumentException::class);
    assertThrows(static fn () => $client->chat('Hello'), RuntimeException::class);
    assertThrows(
        static fn () => new NvidiaClient('key', 'http://nvidia.invalid/v1/chat/completions'),
        RuntimeException::class
    );
    $invalidTransport = new NvidiaClient(
        'key',
        'https://nvidia.invalid/v1/chat/completions',
        transport: static fn (): string => 'invalid'
    );
    assertThrows(static fn () => $invalidTransport->chat('Hello'), RuntimeException::class);
};

$tests['NVIDIA replies fit Telegram without splitting UTF-8 characters'] = static function (): void {
    $reply = Utilities::truncateUnicode('NVIDIA reply:' . str_repeat('é', 5000), 4096);
    assertSame(4096, preg_match_all('/./us', $reply));
    assertSame(true, str_ends_with($reply, '…'));
    assertSame('short reply', Utilities::truncateUnicode('short reply', 4096));
    assertThrows(static fn () => Utilities::truncateUnicode('reply', 65536), InvalidArgumentException::class);
};

$tests['Bot returns a generic response when user persistence fails'] = static function (): void {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $repository = new UserRepository(new Database($pdo));
    $bot = new BotService(
        $repository,
        new ApiTokenService($pdo),
        new Crypto(base64_encode(str_repeat('k', 32))),
        new StripeSandbox(),
        new BinLookup(),
        new NvidiaClient('', 'https://nvidia.invalid/v1/chat/completions'),
        '1',
        5
    );

    assertSame(
        'The command could not be completed. Please try again later.',
        $bot->handle('42', 'alice', '/me')
    );
};

$tests['Usage stats, moderation, and hashed API tokens work together'] = static function (): void {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn (): string => '2026-09-17 00:00:00');
    $pdo->exec('CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT, platform_user_id TEXT UNIQUE, username TEXT,
        is_banned INTEGER DEFAULT 0, is_muted INTEGER DEFAULT 0, merchant_key_cipher TEXT,
        otp_secret_cipher TEXT, last_action_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE TABLE command_usage (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, command TEXT, succeeded INTEGER,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE TABLE api_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, label TEXT, token_prefix TEXT,
        token_hash TEXT UNIQUE, created_by TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        last_used_at TEXT, revoked_at TEXT
    )');
    $pdo->exec("INSERT INTO users (platform_user_id, username) VALUES ('42', 'alice')");

    $repository = new UserRepository(new Database($pdo));
    $user = $repository->findByPlatformId('42');
    assertSame(true, $repository->setFlag('42', 'is_muted', true));
    assertSame(true, $repository->setFlag('42', 'is_muted', true));
    $repository->recordCommand((int) $user['id'], 'stats', true);
    $repository->recordCommand((int) $user['id'], 'bad', false);
    $stats = $repository->userStats((int) $user['id']);
    assertSame(2, (int) $stats['total']);
    assertSame(1, (int) $stats['succeeded']);

    $tokens = new ApiTokenService($pdo);
    $plainToken = $tokens->generate((int) $user['id'], 'phone', '42');
    assertSame(true, str_starts_with($plainToken, 'ghost_'));
    assertSame('42', $tokens->authenticate($plainToken)['platform_user_id']);
    $metadata = $tokens->lookup(substr($plainToken, 0, 22), (int) $user['id']);
    assertSame('phone', $metadata['label']);
    assertSame(true, $tokens->revoke((int) $metadata['id']));
    assertSame(null, $tokens->authenticate($plainToken));
};

function assertSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertThrows(callable $callback, string $expectedClass): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        if ($exception instanceof $expectedClass) {
            return;
        }
        throw new RuntimeException('Unexpected exception: ' . $exception::class);
    }
    throw new RuntimeException("Expected {$expectedClass} to be thrown.");
}

$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS {$name}\n";
    } catch (Throwable $exception) {
        $failed++;
        fwrite(STDERR, "FAIL {$name}: {$exception->getMessage()}\n");
    }
}

echo sprintf("%d tests, %d failures\n", count($tests), $failed);
exit($failed === 0 ? 0 : 1);
