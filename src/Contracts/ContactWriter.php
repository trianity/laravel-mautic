<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Contracts;

use Trianity\LaravelMautic\Data\Contact;
use Trianity\LaravelMautic\Data\ContactBatch;
use Trianity\LaravelMautic\Write\ContactFields;
use Trianity\LaravelMautic\Write\ContactUpdate;

interface ContactWriter
{
    public function create(ContactFields $fields): Contact;

    public function update(int $remoteId, ContactFields $fields): Contact;

    public function createBatch(ContactFields ...$contacts): ContactBatch;

    public function updateBatch(ContactUpdate ...$contacts): ContactBatch;

    public function addToSegment(int $remoteId, int $segmentId): void;

    public function removeFromSegment(int $remoteId, int $segmentId): void;
}
