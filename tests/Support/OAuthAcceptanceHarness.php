<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Tests\Support;

use GuzzleHttp\Psr7\Request;
use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Trianity\LaravelMautic\Configuration\ConnectionConfig;
use Trianity\LaravelMautic\Data\OAuthToken;
use Trianity\LaravelMautic\Data\OAuthTokenRecord;
use Trianity\LaravelMautic\MauticConnection;
use Trianity\LaravelMautic\OAuth\DatabaseOAuthStateRepository;
use Trianity\LaravelMautic\OAuth\DatabaseOAuthTokenRepository;
use Trianity\LaravelMautic\OAuth\DatabaseRefreshLock;
use Trianity\LaravelMautic\OAuth\EnvironmentOAuthClientCredentialsProvider;
use Trianity\LaravelMautic\OAuth\HttpOAuthTokenEndpoint;
use Trianity\LaravelMautic\OAuth\OAuth2Service;
use Trianity\LaravelMautic\OAuth\OAuthTokenManager;
use Trianity\LaravelMautic\OAuth\SystemClock;
use Trianity\LaravelMautic\Support\ApiLibraryAuthenticationFactory;
use Trianity\LaravelMautic\Support\ApiLibraryConnectionFactory;

final class OAuthAcceptanceHarness
{
    private const CONNECTION = 'oauth-acceptance';

    private const STATE_TTL_SECONDS = 600;

    private readonly string $storageDirectory;

    private readonly string $contextPath;

    private readonly Repository $config;

    private readonly ConnectionResolverInterface $database;

    private readonly DatabaseOAuthTokenRepository $tokens;

    private readonly OAuthTokenManager $tokenManager;

    private readonly OAuth2Service $oauth;

    private readonly VerifiedLocalHttpClientFactory $httpClients;

    public function __construct(
        public readonly OAuthAcceptanceConfiguration $environment,
        ?string $storageDirectory = null,
    ) {
        $this->storageDirectory = $storageDirectory ?? dirname(__DIR__).'/.oauth-acceptance';
        $this->contextPath = $this->storageDirectory.'/authorization-context.json';
        $this->prepareStorageDirectory();
        $databasePath = $this->storageDirectory.'/oauth.sqlite';
        $key = $this->encryptionKey();
        $this->database = $this->database($databasePath);
        $this->createSchema();
        chmod($databasePath, 0600);

        $this->config = new Repository(['mautic' => [
            'connections' => [self::CONNECTION => [
                'url' => $environment->origin,
                'auth' => 'oauth2',
                'timeout' => 10,
                'connect_timeout' => 3,
                'oauth' => [
                    'client_id' => $environment->clientId,
                    'client_secret_env' => 'MAUTIC_LIVE_OAUTH_CLIENT_SECRET',
                    'redirect_uri' => $environment->redirectUri,
                    'scopes' => [],
                ],
            ]],
        ]]);
        $clock = new SystemClock;
        $encrypter = new Encrypter($key, 'AES-256-CBC');
        $this->tokens = new DatabaseOAuthTokenRepository($this->database, $encrypter);
        $states = new DatabaseOAuthStateRepository($this->database, $clock);
        $lock = new DatabaseRefreshLock($this->database, $clock);
        $this->httpClients = new VerifiedLocalHttpClientFactory($environment->caBundle);
        $endpoint = new HttpOAuthTokenEndpoint(
            $this->config,
            $this->httpClients,
            new EnvironmentOAuthClientCredentialsProvider($this->config),
            $clock,
        );
        $this->tokenManager = new OAuthTokenManager($this->tokens, $endpoint, $lock, $clock);
        $this->oauth = new OAuth2Service(
            $this->config,
            $states,
            $this->tokens,
            $endpoint,
            $lock,
            self::STATE_TTL_SECONDS,
        );
    }

    public function prepareAuthorization(): string
    {
        $context = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $payload = json_encode(['context' => $context], JSON_THROW_ON_ERROR);
        if (file_put_contents($this->contextPath, $payload, LOCK_EX) !== strlen($payload)
            || ! chmod($this->contextPath, 0600)) {
            throw new RuntimeException('The local OAuth authorization context could not be stored securely.');
        }

        return $this->oauth->authorizationUrl(self::CONNECTION, $context);
    }

    public function completeAuthorization(string $state, string $code): OAuthTokenRecord
    {
        $payload = is_file($this->contextPath)
            ? json_decode((string) file_get_contents($this->contextPath), true, flags: JSON_THROW_ON_ERROR)
            : null;
        $context = is_array($payload) ? ($payload['context'] ?? null) : null;
        if (! is_string($context) || $context === '') {
            throw new RuntimeException('The trusted local OAuth authorization context is unavailable.');
        }

        $this->oauth->complete(self::CONNECTION, $state, $code, $context);
        unlink($this->contextPath);

        return $this->requiredTokenRecord();
    }

    public function requiredTokenRecord(): OAuthTokenRecord
    {
        $record = $this->tokens->record(self::CONNECTION);
        if ($record === null) {
            throw new RuntimeException('Complete browser authorization before running OAuth acceptance probes.');
        }

        return $record;
    }

    public function connection(): MauticConnection
    {
        $configuration = $this->connectionConfiguration();
        $factory = new ApiLibraryConnectionFactory(
            $this->httpClients,
            new ApiLibraryAuthenticationFactory($this->tokenManager),
        );

        return $factory->make($configuration);
    }

    public function refreshExpiredDedicatedToken(): OAuthTokenRecord
    {
        $record = $this->requiredTokenRecord();
        $expired = new OAuthToken(
            $record->token->accessToken,
            $record->token->refreshToken,
            time() - 1,
            $record->token->tokenType,
        );
        if (! $this->tokens->persist(self::CONNECTION, $expired, $record->generation)) {
            throw new RuntimeException('The dedicated acceptance token could not be marked expired safely.');
        }

        $this->tokenManager->validToken(self::CONNECTION);

        return $this->requiredTokenRecord();
    }

    public function invalidBearerProbe(): ResponseInterface
    {
        return $this->httpClients->make($this->connectionConfiguration())->sendRequest(new Request(
            'GET',
            $this->environment->origin.'/api/contacts?limit=1',
            ['Accept' => 'application/json', 'Authorization' => 'Bearer rejected-acceptance-token'],
        ));
    }

    public function apiV2Probe(OAuthToken $token): ResponseInterface
    {
        return $this->httpClients->make($this->connectionConfiguration())->sendRequest(new Request(
            'GET',
            $this->environment->origin.'/api/v2/contacts?page=1',
            ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$token->accessToken],
        ));
    }

    private function connectionConfiguration(): ConnectionConfig
    {
        $values = $this->config->get('mautic.connections.'.self::CONNECTION);
        if (! is_array($values)) {
            throw new RuntimeException('The OAuth acceptance connection configuration is unavailable.');
        }

        return ConnectionConfig::fromArray(self::CONNECTION, $values);
    }

    private function prepareStorageDirectory(): void
    {
        if (! is_dir($this->storageDirectory) && ! mkdir($this->storageDirectory, 0700, true)) {
            throw new RuntimeException('The local OAuth acceptance storage directory could not be created.');
        }
        if (! chmod($this->storageDirectory, 0700)) {
            throw new RuntimeException('The local OAuth acceptance storage directory could not be restricted.');
        }
    }

    private function encryptionKey(): string
    {
        $path = $this->storageDirectory.'/encryption.key';
        if (! is_file($path)) {
            $encoded = base64_encode(random_bytes(32));
            $handle = fopen($path, 'x');
            if ($handle === false || ! chmod($path, 0600)
                || fwrite($handle, $encoded) !== strlen($encoded) || ! fflush($handle)) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
                throw new RuntimeException('The local OAuth acceptance encryption key could not be stored securely.');
            }
            fclose($handle);
        }
        if ((fileperms($path) & 0777) !== 0600) {
            throw new RuntimeException('The local OAuth acceptance encryption key must have mode 0600.');
        }

        $decoded = base64_decode(trim((string) file_get_contents($path)), true);
        if (! is_string($decoded) || strlen($decoded) !== 32) {
            throw new RuntimeException('The local OAuth acceptance encryption key is invalid.');
        }

        return $decoded;
    }

    private function database(string $path): ConnectionResolverInterface
    {
        if (! is_file($path) && ! touch($path)) {
            throw new RuntimeException('The local OAuth acceptance database could not be created.');
        }
        if (! chmod($path, 0600)) {
            throw new RuntimeException('The local OAuth acceptance database could not be restricted.');
        }

        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => $path]);

        return $capsule->getDatabaseManager();
    }

    private function createSchema(): void
    {
        $schema = $this->database->connection()->getSchemaBuilder();
        if (! $schema->hasTable('mautic_oauth_tokens')) {
            $schema->create('mautic_oauth_tokens', function (Blueprint $table): void {
                $table->string('connection')->primary();
                $table->text('token_payload');
                $table->unsignedBigInteger('expires_at');
                $table->unsignedBigInteger('generation');
                $table->timestamps();
            });
        }
        if (! $schema->hasTable('mautic_oauth_states')) {
            $schema->create('mautic_oauth_states', function (Blueprint $table): void {
                $table->string('state_hash', 64)->primary();
                $table->string('connection')->index();
                $table->string('context_hash', 64);
                $table->unsignedBigInteger('expires_at')->index();
                $table->timestamp('created_at');
            });
        }
        if (! $schema->hasTable('mautic_oauth_locks')) {
            $schema->create('mautic_oauth_locks', function (Blueprint $table): void {
                $table->string('name')->primary();
                $table->string('owner', 64);
                $table->unsignedBigInteger('expires_at')->index();
            });
        }
    }
}
