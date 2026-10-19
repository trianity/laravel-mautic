<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Identity;

use Trianity\LaravelMautic\Exceptions\InvalidContactIdentity;

final readonly class LocalContactReference
{
    public function __construct(public string $scope, public string $key)
    {
        if (trim($scope) === '' || trim($key) === '' || strlen($scope) > 191 || strlen($key) > 191) {
            throw new InvalidContactIdentity('Local contact scope and key must be non-empty strings of at most 191 bytes.');
        }
    }
}
