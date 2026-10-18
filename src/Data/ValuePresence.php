<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Data;

enum ValuePresence: string
{
    case Missing = 'missing';
    case Null = 'null';
    case Value = 'value';
}
