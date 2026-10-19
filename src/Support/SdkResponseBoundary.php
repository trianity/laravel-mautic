<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Support;

use Trianity\LaravelMautic\Contracts\OAuthAuthenticationFailureProvider;
use Trianity\LaravelMautic\Exceptions\AmbiguousMutationOutcome;
use Trianity\LaravelMautic\Exceptions\ContactNotFound;
use Trianity\LaravelMautic\Exceptions\ContactUpdateTargetNotFound;
use Trianity\LaravelMautic\Exceptions\MauticReadException;
use Trianity\LaravelMautic\Exceptions\MauticWriteException;
use Trianity\LaravelMautic\Read\ReadFailureKind;
use Trianity\LaravelMautic\Read\ReadOperation;
use Trianity\LaravelMautic\Write\FailureKind;
use Trianity\LaravelMautic\Write\MutationOperation;

final class SdkResponseBoundary
{
    public function __construct(private readonly ?OAuthAuthenticationFailureProvider $authenticationFailures = null) {}

    /**
     * @param  array<mixed>  $response
     * @return array<string, mixed>
     */
    public function successful(
        array $response,
        ReadOperation $operation,
        string $connection,
        ?int $retryAfterSeconds = null,
    ): array {
        $this->throwAuthenticationFailure();
        if (array_key_exists('errors', $response)) {
            $this->throwErrors($response['errors'], $operation, $connection, $retryAfterSeconds);
        }

        if (! $this->isStringKeyed($response)) {
            throw new MauticReadException(ReadFailureKind::UnexpectedResponse, $operation, $connection);
        }

        return $response;
    }

    public function unexpected(ReadOperation $operation, string $connection): never
    {
        throw new MauticReadException(ReadFailureKind::UnexpectedResponse, $operation, $connection);
    }

    /**
     * @param  array<mixed>  $response
     * @return array<string, mixed>
     */
    public function successfulWrite(
        array $response,
        MutationOperation $operation,
        string $connection,
        ?int $batchIndex = null,
        ?int $retryAfterSeconds = null,
    ): array {
        $this->throwAuthenticationFailure();
        if (array_key_exists('errors', $response)) {
            $this->throwWriteErrors($response['errors'], $operation, $connection, $batchIndex, $retryAfterSeconds);
        }

        if (! $this->isStringKeyed($response)) {
            throw new MauticWriteException(
                FailureKind::UnexpectedResponse,
                $operation,
                $connection,
                batchIndex: $batchIndex,
            );
        }

        return $response;
    }

    public function unexpectedWrite(
        MutationOperation $operation,
        string $connection,
        ?int $batchIndex = null,
    ): never {
        throw new MauticWriteException(
            FailureKind::UnexpectedResponse,
            $operation,
            $connection,
            batchIndex: $batchIndex,
        );
    }

    public function writeFailureKind(?int $status, string $message = ''): FailureKind
    {
        return $this->writeKind($this->classify($status, $message));
    }

    public function statusCode(mixed $value): ?int
    {
        return $this->integer($value);
    }

    private function throwAuthenticationFailure(): void
    {
        $failure = $this->authenticationFailures?->pullAuthenticationFailure();
        if ($failure !== null) {
            throw $failure;
        }
    }

    private function throwErrors(
        mixed $errors,
        ReadOperation $operation,
        string $connection,
        ?int $retryAfterSeconds,
    ): never {
        if (! is_array($errors) || $errors === []) {
            $this->unexpected($operation, $connection);
        }

        $first = reset($errors);
        if (! is_array($first)) {
            $this->unexpected($operation, $connection);
        }

        $status = $this->integer($first['code'] ?? null);
        $message = is_string($first['message'] ?? null) ? $first['message'] : '';

        if (in_array($operation, [ReadOperation::FindContact, ReadOperation::ListContactSegments], true)
            && $status === 404) {
            throw new ContactNotFound($connection, $operation);
        }

        throw new MauticReadException(
            $this->classify($status, $message),
            $operation,
            $connection,
            $status,
            $retryAfterSeconds,
        );
    }

    private function throwWriteErrors(
        mixed $errors,
        MutationOperation $operation,
        string $connection,
        ?int $batchIndex,
        ?int $retryAfterSeconds,
    ): never {
        if (! is_array($errors) || $errors === []) {
            $this->unexpectedWrite($operation, $connection, $batchIndex);
        }

        $first = reset($errors);
        if (! is_array($first)) {
            $this->unexpectedWrite($operation, $connection, $batchIndex);
        }

        $status = $this->integer($first['code'] ?? null);
        $message = is_string($first['message'] ?? null) ? $first['message'] : '';

        if (in_array($operation, [MutationOperation::UpdateContact, MutationOperation::UpdateContactsBatch], true)
            && $status === 404) {
            throw new ContactUpdateTargetNotFound($connection, $operation, $batchIndex);
        }

        $kind = $this->writeKind($this->classify($status, $message));
        if ($operation->safety()->ambiguousOnTransportFailure
            && ($kind === FailureKind::Timeout
                || ($kind === FailureKind::Transport && in_array($status, [null, 0], true)))) {
            throw new AmbiguousMutationOutcome($operation, $connection, $batchIndex);
        }

        throw new MauticWriteException($kind, $operation, $connection, $status, $batchIndex, $retryAfterSeconds);
    }

    private function classify(?int $status, string $message): ReadFailureKind
    {
        if (str_contains($message, 'unexpected format')) {
            return ReadFailureKind::MalformedResponse;
        }

        if (preg_match('/timed? out|timeout/i', $message) === 1) {
            return ReadFailureKind::Timeout;
        }

        return match ($status) {
            401, 403 => ReadFailureKind::Authentication,
            400, 405, 409, 422 => ReadFailureKind::Validation,
            408, 504 => ReadFailureKind::Timeout,
            429 => ReadFailureKind::Throttled,
            404 => ReadFailureKind::NotFound,
            500, 502, 503 => ReadFailureKind::RemoteServer,
            default => ReadFailureKind::Transport,
        };
    }

    private function writeKind(ReadFailureKind $kind): FailureKind
    {
        return match ($kind) {
            ReadFailureKind::Authentication => FailureKind::Authentication,
            ReadFailureKind::Validation => FailureKind::Validation,
            ReadFailureKind::Timeout => FailureKind::Timeout,
            ReadFailureKind::Throttled => FailureKind::Throttled,
            ReadFailureKind::MalformedResponse => FailureKind::MalformedResponse,
            ReadFailureKind::UnexpectedResponse => FailureKind::UnexpectedResponse,
            ReadFailureKind::RemoteServer => FailureKind::RemoteServer,
            ReadFailureKind::Transport => FailureKind::Transport,
            ReadFailureKind::NotFound => FailureKind::TargetNotFound,
        };
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    /** @param array<mixed> $value */
    private function isStringKeyed(array $value): bool
    {
        foreach (array_keys($value) as $key) {
            if (! is_string($key)) {
                return false;
            }
        }

        return true;
    }
}
