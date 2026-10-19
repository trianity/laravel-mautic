<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Exceptions;

use RuntimeException;

final class OAuthTokenRequestFailed extends RuntimeException
{
    public function __construct(public readonly string $connection, public readonly ?int $status = null)
    {
        parent::__construct('The OAuth token endpoint request failed.');
    }
}
