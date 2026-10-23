<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Support;

use Mautic\Api\Segments;
use Trianity\LaravelMautic\Contracts\StaticSegmentGateway;
use Trianity\LaravelMautic\Exceptions\InvalidReadRequest;
use Trianity\LaravelMautic\Exceptions\InvalidWriteRequest;
use Trianity\LaravelMautic\Exceptions\SegmentConflict;
use Trianity\LaravelMautic\Read\ReadOperation;
use Trianity\LaravelMautic\Segment\StaticSegment;
use Trianity\LaravelMautic\Segment\StaticSegmentDefinition;
use Trianity\LaravelMautic\Write\MutationOperation;

final readonly class SdkStaticSegmentGateway implements StaticSegmentGateway
{
    public function __construct(
        private Segments $segments,
        private string $connection,
        private SdkResponseBoundary $boundary,
    ) {}

    public function find(int $remoteId): StaticSegment
    {
        if ($remoteId < 1) {
            throw new InvalidReadRequest('A positive remote segment ID is required.');
        }
        $operation = ReadOperation::FindSegment;
        $raw = $this->segments->get($remoteId);
        $response = $this->boundary->successful(
            $raw,
            $operation,
            $this->connection,
            SdkResponseMetadata::retryAfterForResponse($raw, $this->segments),
        );
        if (! array_key_exists('list', $response)) {
            $this->boundary->unexpected($operation, $this->connection);
        }

        return $this->map($response['list'], $operation);
    }

    public function candidates(string $alias, int $limit = 10): array
    {
        if (trim($alias) === '' || $limit < 1 || $limit > 100) {
            throw new InvalidReadRequest('A segment alias and a limit between 1 and 100 are required.');
        }
        $operation = ReadOperation::SearchSegments;
        $raw = $this->segments->getList($alias, 0, $limit);
        $response = $this->boundary->successful(
            $raw,
            $operation,
            $this->connection,
            SdkResponseMetadata::retryAfterForResponse($raw, $this->segments),
        );
        $total = $this->boundary->nonNegativeInteger($response['total'] ?? null);
        if ($total === null || ! is_array($response['lists'] ?? null) || count($response['lists']) > $limit) {
            $this->boundary->unexpected($operation, $this->connection);
        }
        if ($total > count($response['lists'])) {
            throw new SegmentConflict('The bounded segment query cannot prove an unambiguous exact-alias result.');
        }

        $matches = [];
        foreach ($response['lists'] as $candidate) {
            $segment = $this->map($candidate, $operation);
            if ($segment->alias === $alias) {
                $matches[] = $segment;
            }
        }

        return $matches;
    }

    public function create(StaticSegmentDefinition $definition): StaticSegment
    {
        $operation = MutationOperation::CreateStaticSegment;
        $raw = $this->segments->create($definition->payload());
        $response = $this->boundary->successfulWrite(
            $raw,
            $operation,
            $this->connection,
            retryAfterSeconds: SdkResponseMetadata::retryAfterForResponse($raw, $this->segments),
        );
        if (! array_key_exists('list', $response)) {
            $this->boundary->unexpectedWrite($operation, $this->connection);
        }
        $segment = $this->map($response['list'], ReadOperation::FindSegment);
        if ($segment->name !== $definition->name || $segment->alias !== $definition->alias || ! $segment->suitable()) {
            throw new InvalidWriteRequest('The created Mautic segment does not match the requested static definition.');
        }

        return $segment;
    }

    private function map(mixed $value, ReadOperation $operation): StaticSegment
    {
        if (! is_array($value)) {
            $this->boundary->unexpected($operation, $this->connection);
        }
        $id = $this->boundary->nonNegativeInteger($value['id'] ?? null);
        $name = $value['name'] ?? null;
        $alias = $value['alias'] ?? null;
        $published = $value['isPublished'] ?? null;
        $global = $value['isGlobal'] ?? null;
        $filters = $value['filters'] ?? null;
        if ($id === null || $id < 1 || ! is_string($name) || trim($name) === ''
            || ! is_string($alias) || trim($alias) === '' || ! is_bool($published)
            || ! is_bool($global) || ! is_array($filters) || ! array_is_list($filters)) {
            $this->boundary->unexpected($operation, $this->connection);
        }
        foreach ($filters as $filter) {
            if (! is_array($filter)) {
                $this->boundary->unexpected($operation, $this->connection);
            }
        }

        return new StaticSegment($id, $name, $alias, $published, $global, $filters);
    }
}
