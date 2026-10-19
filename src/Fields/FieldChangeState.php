<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Fields;

enum FieldChangeState: string
{
    case Unchanged = 'unchanged';
    case Clear = 'clear';
    case Set = 'set';
}
