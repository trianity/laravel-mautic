<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Data;

enum DoNotContactReason: int
{
    case Contactable = 0;
    case Unsubscribed = 1;
    case Bounced = 2;
    case Manual = 3;
}
