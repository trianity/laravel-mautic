<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Data;

final readonly class Contact
{
    /** @var OptionalValue<array<string, OptionalValue<mixed>>> */
    public OptionalValue $fields;

    /** @var OptionalValue<list<ContactTag>> */
    public OptionalValue $tags;

    /** @var OptionalValue<list<DoNotContactEntry>> */
    public OptionalValue $doNotContact;

    /**
     * @param  OptionalValue<array<string, OptionalValue<mixed>>>|null  $fields
     * @param  OptionalValue<list<ContactTag>>|null  $tags
     * @param  OptionalValue<list<DoNotContactEntry>>|null  $doNotContact
     */
    public function __construct(
        public int $remoteId,
        ?OptionalValue $fields = null,
        ?OptionalValue $tags = null,
        ?OptionalValue $doNotContact = null,
    ) {
        $this->fields = $fields ?? OptionalValue::missing();
        $this->tags = $tags ?? OptionalValue::missing();
        $this->doNotContact = $doNotContact ?? OptionalValue::missing();
    }

    /** @return OptionalValue<mixed> */
    public function field(string $alias): OptionalValue
    {
        if ($this->fields->presence !== ValuePresence::Value) {
            return $this->fields->presence === ValuePresence::Null
                ? OptionalValue::null()
                : OptionalValue::missing();
        }

        $fields = $this->fields->value();

        return $fields[$alias] ?? OptionalValue::missing();
    }
}
