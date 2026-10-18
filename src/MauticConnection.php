<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic;

use Mautic\Api\Api;
use Mautic\Api\Contacts;
use Mautic\Api\Segments;
use Mautic\Auth\AuthInterface;
use Mautic\MauticApi;
use Trianity\LaravelMautic\Contracts\ContactReader;
use Trianity\LaravelMautic\Contracts\ContactWriter;
use Trianity\LaravelMautic\Support\SdkContactReader;
use Trianity\LaravelMautic\Support\SdkContactWriter;
use Trianity\LaravelMautic\Support\SdkResponseBoundary;

final readonly class MauticConnection
{
    public function __construct(
        public string $name,
        private string $apiUrl,
        private AuthInterface $auth,
    ) {}

    /** Raw upstream context: inspect the response for errors before using it. */
    public function contacts(): Contacts
    {
        return new Contacts($this->auth, $this->apiUrl);
    }

    /** Package-owned, normalized contact and segment reads. */
    public function contactReads(): ContactReader
    {
        return new SdkContactReader($this->contacts(), $this->name, new SdkResponseBoundary);
    }

    /** Package-owned, normalized explicit contact mutations. */
    public function contactWrites(): ContactWriter
    {
        return new SdkContactWriter(
            $this->contacts(),
            new Segments($this->auth, $this->apiUrl),
            $this->name,
            new SdkResponseBoundary,
        );
    }

    /** Low-level escape hatch outside the normalized service contract. */
    public function api(string $context): Api
    {
        return (new MauticApi)->newApi($context, $this->auth, $this->apiUrl);
    }
}
