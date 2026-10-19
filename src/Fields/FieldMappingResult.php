<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Fields;

use Trianity\LaravelMautic\Exceptions\InvalidFieldMapping;

final readonly class FieldMappingResult
{
    /**
     * @param  array<string, FieldChange>  $outbound
     * @param  array<string, FieldChange>  $inbound
     */
    public function __construct(private array $outbound, private array $inbound) {}

    public function outbound(string $mauticField): FieldChange
    {
        return $this->outbound[$mauticField]
            ?? throw new InvalidFieldMapping('The Mautic field is not allowlisted by this mapper.');
    }

    public function inbound(string $localField): FieldChange
    {
        return $this->inbound[$localField]
            ?? throw new InvalidFieldMapping('The host field is not allowlisted by this mapper.');
    }

    /** @return array<string, mixed> */
    public function outboundValues(): array
    {
        return $this->changedValues($this->outbound);
    }

    /** @return array<string, mixed> */
    public function inboundValues(): array
    {
        return $this->changedValues($this->inbound);
    }

    /**
     * @param  array<string, FieldChange>  $changes
     * @return array<string, mixed>
     */
    private function changedValues(array $changes): array
    {
        $values = [];
        foreach ($changes as $field => $change) {
            if ($change->state === FieldChangeState::Clear) {
                $values[$field] = null;
            } elseif ($change->state === FieldChangeState::Set) {
                $values[$field] = $change->value();
            }
        }

        return $values;
    }
}
