<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Support;

final class ContactEmailNormalizer
{
    public static function normalize(string $email): string
    {
        return strtolower(trim($email));
    }
}
