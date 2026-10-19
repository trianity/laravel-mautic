<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Fields;

use Trianity\LaravelMautic\Data\Contact;
use Trianity\LaravelMautic\Data\OptionalValue;
use Trianity\LaravelMautic\Data\ValuePresence;
use Trianity\LaravelMautic\Exceptions\InvalidFieldMapping;

final readonly class FieldMapper
{
    /** @var list<FieldRule> */
    private array $rules;

    public function __construct(FieldRule ...$rules)
    {
        if ($rules === []) {
            throw new InvalidFieldMapping('At least one field ownership rule is required.');
        }
        $local = [];
        $remote = [];
        foreach ($rules as $rule) {
            if (isset($local[$rule->localField]) || isset($remote[$rule->mauticField])) {
                throw new InvalidFieldMapping('Field ownership rules cannot contain duplicate aliases.');
            }
            $local[$rule->localField] = true;
            $remote[$rule->mauticField] = true;
        }
        $this->rules = array_values($rules);
    }

    public function resolve(HostContactFields $host, Contact $mautic): FieldMappingResult
    {
        $outbound = [];
        $inbound = [];
        foreach ($this->rules as $rule) {
            if (! $host->allows($rule->localField)) {
                throw new InvalidFieldMapping('Every mapped host field must be present in the host allowlist.');
            }
            $hostValue = $host->field($rule->localField);
            $mauticValue = $mautic->field($rule->mauticField);
            $hostWins = $rule->ownership === FieldOwnership::Host
                || ($rule->ownership === FieldOwnership::Shared
                    && $rule->sharedResolution === SharedFieldResolution::HostWins);

            if ($hostWins) {
                $outbound[$rule->mauticField] = $this->change($hostValue, $mauticValue);
                $inbound[$rule->localField] = FieldChange::unchanged();
            } else {
                $outbound[$rule->mauticField] = FieldChange::unchanged();
                $inbound[$rule->localField] = $this->change($mauticValue, $hostValue);
            }
        }

        return new FieldMappingResult($outbound, $inbound);
    }

    /**
     * @param  OptionalValue<mixed>  $source
     * @param  OptionalValue<mixed>  $target
     */
    private function change(OptionalValue $source, OptionalValue $target): FieldChange
    {
        if ($source->presence === ValuePresence::Missing) {
            return FieldChange::unchanged();
        }
        if ($source->presence === $target->presence
            && ($source->presence !== ValuePresence::Value || $source->value() === $target->value())) {
            return FieldChange::unchanged();
        }

        return $source->presence === ValuePresence::Null
            ? FieldChange::clear()
            : FieldChange::set($source->value());
    }
}
