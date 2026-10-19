<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Inbox;

enum WebhookIngressFailureKind: string
{
    case UnknownConnection = 'unknown_connection';
    case SecretUnavailable = 'secret_unavailable';
    case BodyTooLarge = 'body_too_large';
    case MissingSignature = 'missing_signature';
    case InvalidSignature = 'invalid_signature';
    case InvalidJson = 'invalid_json';
    case InvalidPayload = 'invalid_payload';
    case Persistence = 'persistence';

    public function message(): string
    {
        return match ($this) {
            self::UnknownConnection => 'The webhook connection is not configured.',
            self::SecretUnavailable => 'The webhook secret is unavailable.',
            self::BodyTooLarge => 'The webhook request body exceeds the configured limit.',
            self::MissingSignature => 'The webhook signature is missing.',
            self::InvalidSignature => 'The webhook signature is invalid.',
            self::InvalidJson => 'The webhook request body is not valid JSON.',
            self::InvalidPayload => 'The webhook payload structure is invalid.',
            self::Persistence => 'The webhook delivery could not be persisted.',
        };
    }
}
