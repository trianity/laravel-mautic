<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Contracts;

use Trianity\LaravelMautic\Restriction\EmailRestrictionMutationResult;
use Trianity\LaravelMautic\Restriction\ManualEmailBlock;

interface ContactRestrictionWriter
{
    public function blockEmail(int $remoteId, ManualEmailBlock $operation): EmailRestrictionMutationResult;

    public function releaseEmailRestriction(int $remoteId): EmailRestrictionMutationResult;
}
