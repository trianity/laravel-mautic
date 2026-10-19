<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Fields;

use Trianity\LaravelMautic\Exceptions\InvalidFieldMapping;

final readonly class FieldRule
{
    private const PROTECTED_MAUTIC_ALIASES = [
        'id',
        'donotcontact',
        'dnc',
        'tags',
        'segments',
        'segment',
        'consent',
        'consent_evidence',
        'dateadded',
        'datemodified',
        'lastactive',
    ];

    public function __construct(
        public string $localField,
        public string $mauticField,
        public FieldOwnership $ownership,
        public ?SharedFieldResolution $sharedResolution = null,
    ) {
        if (trim($localField) === '' || trim($mauticField) === '') {
            throw new InvalidFieldMapping('Local and Mautic field aliases cannot be blank.');
        }
        if (in_array(strtolower($mauticField), self::PROTECTED_MAUTIC_ALIASES, true)) {
            throw new InvalidFieldMapping('DNC, consent, segments and server-managed state are outside contact field mapping.');
        }
        if (($ownership === FieldOwnership::Shared) !== ($sharedResolution !== null)) {
            throw new InvalidFieldMapping('Shared ownership requires an explicit resolution and other ownership does not use one.');
        }
    }
}
