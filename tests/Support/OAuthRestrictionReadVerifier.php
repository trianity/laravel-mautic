<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Tests\Support;

use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;
use Trianity\LaravelMautic\Data\BounceEvidence;
use Trianity\LaravelMautic\Data\Contact;
use Trianity\LaravelMautic\Data\DoNotContactEntry;
use Trianity\LaravelMautic\Data\DoNotContactReason;
use Trianity\LaravelMautic\Data\ValuePresence;

final readonly class OAuthRestrictionReadVerifier
{
    public function verify(
        Contact $first,
        Contact $second,
        OAuthRestrictionReadConfiguration $configuration,
        ?ValuePresence $expectedDateAddedPresence = null,
    ): void {
        $firstEntry = $this->selectedEntry($first, $configuration, $expectedDateAddedPresence);
        $secondEntry = $this->selectedEntry($second, $configuration, $expectedDateAddedPresence);

        if ($this->snapshot($firstEntry) !== $this->snapshot($secondEntry)) {
            throw new RuntimeException('The selected restriction changed between reads; no restoration was attempted.');
        }
    }

    private function selectedEntry(
        Contact $contact,
        OAuthRestrictionReadConfiguration $configuration,
        ?ValuePresence $expectedDateAddedPresence,
    ): DoNotContactEntry {
        if ($contact->remoteId !== $configuration->contactId) {
            throw new RuntimeException('The read-only reference contact ID did not match configuration.');
        }

        $email = $contact->field('email');
        if ($email->presence !== ValuePresence::Value || ! is_string($email->value())
            || ! hash_equals($configuration->contactEmail, $email->value())) {
            throw new RuntimeException('The read-only reference contact email did not match configuration.');
        }

        if ($contact->doNotContact->presence !== ValuePresence::Value
            || $contact->doNotContact->value() === []) {
            throw new RuntimeException('The read-only reference contact has no available restriction entries.');
        }

        $matches = array_values(array_filter(
            $contact->doNotContact->value(),
            static fn (DoNotContactEntry $entry): bool => $entry->remoteId->presence === ValuePresence::Value
                && $entry->remoteId->value() === $configuration->dncId,
        ));
        if (count($matches) !== 1) {
            throw new RuntimeException('The configured DNC ID must match exactly one restriction entry.');
        }

        $entry = $matches[0];
        if ($entry->channel !== 'email') {
            throw new RuntimeException('The selected restriction channel is not email.');
        }
        if ($entry->reason->presence !== ValuePresence::Value
            || ! in_array($entry->reason->value(), [3, '3'], true)
            || $entry->knownReason() !== DoNotContactReason::Manual) {
            throw new RuntimeException('The selected restriction is not the verified manual reason.');
        }
        if ($entry->channelId->presence !== ValuePresence::Null) {
            throw new RuntimeException('The selected restriction channel ID must be explicitly null.');
        }
        if ($entry->comments->presence !== ValuePresence::Value
            || ! is_string($entry->comments->value())
            || ! hash_equals($configuration->comments, $entry->comments->value())) {
            throw new RuntimeException('The selected restriction comments did not match configuration.');
        }
        if ($expectedDateAddedPresence !== null
            && $entry->dateAdded->presence !== $expectedDateAddedPresence) {
            throw new RuntimeException('The selected restriction date added presence did not match the reference contract.');
        }
        if ($entry->dateAdded->presence === ValuePresence::Value
            && (! is_string($entry->dateAdded->value())
                || ! $this->isRfc3339($entry->dateAdded->value()))) {
            throw new RuntimeException('The selected restriction date added is not valid RFC3339.');
        }
        if ($entry->bounceEvidence() !== BounceEvidence::NotBounced) {
            throw new RuntimeException('The manual restriction has unexpected per-entry bounce evidence.');
        }

        return $entry;
    }

    /** @return array{int, string, int|string, null, string, ValuePresence, string|null} */
    private function snapshot(DoNotContactEntry $entry): array
    {
        return [
            $entry->remoteId->value(),
            $entry->channel,
            $entry->reason->value(),
            $entry->channelId->value(),
            $entry->comments->value(),
            $entry->dateAdded->presence,
            $entry->dateAdded->presence === ValuePresence::Value
                ? $entry->dateAdded->value()
                : null,
        ];
    }

    private function isRfc3339(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat(DateTimeInterface::RFC3339, $value);
        $errors = DateTimeImmutable::getLastErrors();

        return $date !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format(DateTimeInterface::RFC3339) === $value;
    }
}
