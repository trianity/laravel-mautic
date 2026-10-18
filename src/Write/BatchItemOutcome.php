<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Write;

enum BatchItemOutcome: string
{
    case Successful = 'successful';
    case Failed = 'failed';
    case Unknown = 'unknown';
}
