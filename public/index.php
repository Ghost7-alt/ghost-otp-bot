<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use GhostBot\Application;

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

try {
    $headers = [];
    foreach (getallheaders() as $name => $value) {
        $headers[strtolower($name)] = $value;
    }
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    [$status, $responseHeaders, $responseBody] = (new Application())->handle(
        $_SERVER['REQUEST_METHOD'] ?? 'GET',
        $path,
        file_get_contents('php://input') ?: '',
        $headers
    );
} catch (JsonException $exception) {
    $status = 400;
    $responseHeaders = ['Content-Type' => 'application/json; charset=utf-8'];
    $responseBody = json_encode(['error' => 'Malformed JSON']);
} catch (Throwable $exception) {
    error_log(sprintf(
        '%s at %s:%d: %s',
        $exception::class,
        $exception->getFile(),
        $exception->getLine(),
        $exception->getMessage()
    ));
    $status = 500;
    $responseHeaders = ['Content-Type' => 'application/json; charset=utf-8'];
    $responseBody = json_encode(['error' => 'Internal server error']);
}

http_response_code($status);
foreach ($responseHeaders as $name => $value) {
    header($name . ': ' . $value);
}
echo $responseBody;
