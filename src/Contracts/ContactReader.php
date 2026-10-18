<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Contracts;

use Trianity\LaravelMautic\Data\Contact;
use Trianity\LaravelMautic\Data\ContactPage;
use Trianity\LaravelMautic\Data\SegmentMemberships;
use Trianity\LaravelMautic\Read\ContactSearch;

interface ContactReader
{
    public function find(int $remoteId): Contact;

    public function search(ContactSearch $search): ContactPage;

    public function segments(int $remoteId): SegmentMemberships;
}
