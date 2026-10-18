<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Support;

use Trianity\LaravelMautic\Data\Contact;
use Trianity\LaravelMautic\Data\ContactTag;
use Trianity\LaravelMautic\Data\DoNotContactEntry;
use Trianity\LaravelMautic\Data\OptionalValue;
use Trianity\LaravelMautic\Data\SegmentMembership;
use Trianity\LaravelMautic\Exceptions\MauticReadException;
use Trianity\LaravelMautic\Read\ReadFailureKind;
use Trianity\LaravelMautic\Read\ReadOperation;

final class ContactResponseMapper
{
    public function __construct(private readonly string $connection) {}

    public function contact(mixed $payload, ReadOperation $operation): Contact
    {
        if (! is_array($payload) || ! is_int($payload['id'] ?? null)) {
            $this->unexpected($operation);
        }

        return new Contact(
            remoteId: $payload['id'],
            fields: $this->fields($payload, $operation),
            tags: $this->tags($payload, $operation),
            doNotContact: $this->doNotContact($payload, $operation),
        );
    }

    /** @return list<SegmentMembership> */
    public function segments(mixed $payload, ReadOperation $operation): array
    {
        if (! is_array($payload)) {
            $this->unexpected($operation);
        }

        $memberships = [];
        foreach ($payload as $segment) {
            if (! is_array($segment)
                || ! is_int($segment['id'] ?? null)
                || ! is_string($segment['name'] ?? null)
                || (isset($segment['alias']) && ! is_string($segment['alias']))) {
                $this->unexpected($operation);
            }

            $memberships[] = new SegmentMembership(
                $segment['id'],
                $segment['name'],
                $segment['alias'] ?? null,
            );
        }

        return $memberships;
    }

    /**
     * @param  array<mixed>  $contact
     * @return OptionalValue<array<string, OptionalValue<mixed>>>
     */
    private function fields(array $contact, ReadOperation $operation): OptionalValue
    {
        if (! array_key_exists('fields', $contact)) {
            return OptionalValue::missing();
        }

        if ($contact['fields'] === null) {
            return OptionalValue::null();
        }

        if (! is_array($contact['fields']) || ! array_key_exists('all', $contact['fields'])) {
            $this->unexpected($operation);
        }

        $all = $contact['fields']['all'];
        if ($all === null) {
            return OptionalValue::null();
        }

        if (! is_array($all)) {
            $this->unexpected($operation);
        }

        $fields = [];
        foreach ($all as $alias => $value) {
            if (! is_string($alias)) {
                $this->unexpected($operation);
            }

            $fields[$alias] = OptionalValue::present($value);
        }

        return OptionalValue::present($fields);
    }

    /**
     * @param  array<mixed>  $contact
     * @return OptionalValue<list<ContactTag>>
     */
    private function tags(array $contact, ReadOperation $operation): OptionalValue
    {
        if (! array_key_exists('tags', $contact)) {
            return OptionalValue::missing();
        }

        if ($contact['tags'] === null) {
            return OptionalValue::null();
        }

        if (! is_array($contact['tags'])) {
            $this->unexpected($operation);
        }

        $tags = [];
        foreach ($contact['tags'] as $tag) {
            if (! is_array($tag)) {
                $this->unexpected($operation);
            }

            $name = $tag['tag'] ?? $tag['name'] ?? null;
            $remoteId = $tag['id'] ?? null;
            if (! is_string($name) || ($remoteId !== null && ! is_int($remoteId))) {
                $this->unexpected($operation);
            }

            $tags[] = new ContactTag($remoteId, $name);
        }

        return OptionalValue::present($tags);
    }

    /**
     * @param  array<mixed>  $contact
     * @return OptionalValue<list<DoNotContactEntry>>
     */
    private function doNotContact(array $contact, ReadOperation $operation): OptionalValue
    {
        if (! array_key_exists('doNotContact', $contact)) {
            return OptionalValue::missing();
        }

        if ($contact['doNotContact'] === null) {
            return OptionalValue::null();
        }

        if (! is_array($contact['doNotContact'])) {
            $this->unexpected($operation);
        }

        $entries = [];
        foreach ($contact['doNotContact'] as $entry) {
            if (! is_array($entry)
                || ! is_string($entry['channel'] ?? null)
                || (isset($entry['id']) && ! is_int($entry['id']))) {
                $this->unexpected($operation);
            }

            $entries[] = new DoNotContactEntry(
                $entry['id'] ?? null,
                $entry['channel'],
                $this->optionalReason($entry, $operation),
                $this->optionalComments($entry, $operation),
            );
        }

        return OptionalValue::present($entries);
    }

    /**
     * @param  array<mixed>  $entry
     * @return OptionalValue<int|string>
     */
    private function optionalReason(array $entry, ReadOperation $operation): OptionalValue
    {
        if (! array_key_exists('reason', $entry)) {
            return OptionalValue::missing();
        }

        if ($entry['reason'] === null) {
            return OptionalValue::null();
        }

        if (! is_int($entry['reason']) && ! is_string($entry['reason'])) {
            $this->unexpected($operation);
        }

        return OptionalValue::present($entry['reason']);
    }

    /**
     * @param  array<mixed>  $entry
     * @return OptionalValue<string>
     */
    private function optionalComments(array $entry, ReadOperation $operation): OptionalValue
    {
        if (! array_key_exists('comments', $entry)) {
            return OptionalValue::missing();
        }

        if ($entry['comments'] === null) {
            return OptionalValue::null();
        }

        if (! is_string($entry['comments'])) {
            $this->unexpected($operation);
        }

        return OptionalValue::present($entry['comments']);
    }

    private function unexpected(ReadOperation $operation): never
    {
        throw new MauticReadException(ReadFailureKind::UnexpectedResponse, $operation, $this->connection);
    }
}
