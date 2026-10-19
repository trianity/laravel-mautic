<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Inbox;

use RuntimeException;
use Trianity\LaravelMautic\Data\Contact;
use Trianity\LaravelMautic\Data\OptionalValue;

final class WebhookContactMapper
{
    public function map(StoredWebhookEvent $event): Contact
    {
        $contact = $event->payload['contact'] ?? $event->payload['lead'] ?? null;
        if (! is_array($contact) || ! is_int($contact['id'] ?? null) || $contact['id'] <= 0) {
            throw new RuntimeException('The webhook contact payload is invalid.');
        }

        return new Contact($contact['id'], OptionalValue::present($this->fields($contact)));
    }

    /**
     * @param  array<string, mixed>  $contact
     * @return array<string, OptionalValue<mixed>>
     */
    private function fields(array $contact): array
    {
        $raw = $contact['fields'] ?? null;
        if (! is_array($raw)) {
            throw new RuntimeException('The webhook contact fields are invalid.');
        }

        if (is_array($raw['all'] ?? null)) {
            $fields = [];
            foreach ($raw['all'] as $alias => $value) {
                if (is_string($alias)) {
                    $fields[$alias] = $value === null ? OptionalValue::null() : OptionalValue::present($value);
                }
            }

            return $fields;
        }

        $fields = [];
        foreach ($raw as $group) {
            if (! is_array($group)) {
                continue;
            }
            foreach ($group as $fallbackAlias => $definition) {
                if (! is_array($definition)) {
                    continue;
                }
                $alias = $definition['alias'] ?? $fallbackAlias;
                if (! is_string($alias) || ! array_key_exists('value', $definition)) {
                    continue;
                }
                $value = $definition['value'];
                $fields[$alias] = $value === null ? OptionalValue::null() : OptionalValue::present($value);
            }
        }

        return $fields;
    }
}
