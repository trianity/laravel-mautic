<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Support;

use Trianity\LaravelMautic\Exceptions\ContactNotFound;
use Trianity\LaravelMautic\Exceptions\MauticReadException;
use Trianity\LaravelMautic\Read\ReadFailureKind;
use Trianity\LaravelMautic\Read\ReadOperation;

final class SdkResponseBoundary
{
    /**
     * @param  array<mixed>  $response
     * @return array<string, mixed>
     */
    public function successful(array $response, ReadOperation $operation, string $connection): array
    {
        if (array_key_exists('errors', $response)) {
            $this->throwErrors($response['errors'], $operation, $connection);
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

    private function throwErrors(mixed $errors, ReadOperation $operation, string $connection): never
    {
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
        );
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
            500, 502, 503 => ReadFailureKind::RemoteServer,
            default => ReadFailureKind::Transport,
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
