<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PDO;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Trianity\LaravelMautic\Tests\Support\BudgetedHttpClient;
use Trianity\LaravelMautic\Tests\Support\OAuthAcceptanceRequestBudget;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$path = $argv[1] ?? '';
$consumer = $argv[2] ?? '';
$attempts = (int) ($argv[3] ?? 0);
$budget = OAuthAcceptanceRequestBudget::shared($path, $consumer, 3, 2, cleanupAllowed: false);
$transport = new class($path) implements ClientInterface
{
    private readonly PDO $database;

    public function __construct(string $path)
    {
        $this->database = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->database->exec('PRAGMA busy_timeout = 15000');
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->database->exec(
            'UPDATE subscription_live_burst_transport_attempts SET attempts = attempts + 1 WHERE id = 1',
        );

        return new Response(200);
    }
};
$client = new BudgetedHttpClient($transport, $budget);
for ($attempt = 0; $attempt < $attempts; $attempt++) {
    try {
        $client->sendRequest(new Request('GET', 'https://mautic.test/api/contacts'));
    } catch (RuntimeException) {
        // Budget rejection is an expected result in this contention helper.
    }
}
