<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic;

use Mautic\Api\Api;
use Mautic\Api\Contacts;
use Mautic\Auth\AuthInterface;
use Mautic\MauticApi;

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

    /** Low-level escape hatch. Normalized application services are planned. */
    public function api(string $context): Api
    {
        return (new MauticApi)->newApi($context, $this->auth, $this->apiUrl);
    }
}
