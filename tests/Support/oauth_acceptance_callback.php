<?php

declare(strict_types=1);

use Trianity\LaravelMautic\Tests\Support\OAuthAcceptanceConfiguration;
use Trianity\LaravelMautic\Tests\Support\OAuthAcceptanceHarness;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$server = stream_socket_server('tcp://127.0.0.1:8765', $errorCode, $errorMessage);
if ($server === false) {
    throw new RuntimeException('The dedicated loopback callback listener could not start.');
}

fwrite(STDOUT, "OAuth callback listener ready on 127.0.0.1:8765.\n");
$client = stream_socket_accept($server, 600);
if ($client === false) {
    throw new RuntimeException('The OAuth callback did not arrive within ten minutes.');
}

$requestLine = fgets($client);
$headers = [];
while (($line = fgets($client)) !== false && trim($line) !== '') {
    $headers[] = $line;
}

$status = '400 Bad Request';
$message = 'OAuth callback rejected.';
try {
    if (! is_string($requestLine) || preg_match('#^GET ([^ ]+) HTTP/1\.[01]\r?\n$#', $requestLine, $matches) !== 1) {
        throw new RuntimeException('Invalid callback request.');
    }
    $host = null;
    foreach ($headers as $header) {
        if (str_starts_with(strtolower($header), 'host:')) {
            $host = trim(substr($header, 5));
            break;
        }
    }
    if ($host !== '127.0.0.1:8765') {
        throw new RuntimeException('Invalid callback host.');
    }
    $target = parse_url($matches[1]);
    if (! is_array($target) || ($target['path'] ?? null) !== '/oauth/callback') {
        throw new RuntimeException('Invalid callback path.');
    }
    parse_str(is_string($target['query'] ?? null) ? $target['query'] : '', $query);
    $state = $query['state'] ?? null;
    $code = $query['code'] ?? null;
    if (! is_string($state) || ! is_string($code) || $state === '' || $code === '') {
        throw new RuntimeException('The callback is missing state or code.');
    }

    $harness = new OAuthAcceptanceHarness(OAuthAcceptanceConfiguration::fromEnvironment());
    $harness->completeAuthorization($state, $code);
    $status = '200 OK';
    $message = 'OAuth authorization completed. You may close this window.';
} catch (Throwable) {
    $message = 'OAuth callback failed. Return to the terminal for the sanitized result.';
}

$body = '<!doctype html><meta charset="utf-8"><title>OAuth acceptance</title><p>'.htmlspecialchars($message).'</p>';
fwrite($client, "HTTP/1.1 {$status}\r\nContent-Type: text/html; charset=utf-8\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n{$body}");
fclose($client);
fclose($server);

if ($status !== '200 OK') {
    throw new RuntimeException('OAuth callback processing failed without exposing callback parameters.');
}

fwrite(STDOUT, "OAuth callback completed and the encrypted token was persisted.\n");
