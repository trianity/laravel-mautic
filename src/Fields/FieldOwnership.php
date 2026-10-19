<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Fields;

enum FieldOwnership: string
{
    case Host = 'host';
    case Mautic = 'mautic';
    case Shared = 'shared';
}
