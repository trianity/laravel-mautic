<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Identity;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use RuntimeException;
use Trianity\LaravelMautic\Contracts\ContactMappingRepository;
use Trianity\LaravelMautic\Exceptions\ContactMappingChanged;
use Trianity\LaravelMautic\Exceptions\ContactMappingConflict;
use Trianity\LaravelMautic\Exceptions\IncompatibleContactMappingData;
use Trianity\LaravelMautic\Exceptions\InvalidContactIdentity;

final readonly class DatabaseContactMappingRepository implements ContactMappingRepository
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $databaseConnection = null,
        private string $table = 'mautic_contact_mappings',
    ) {}

    public function find(string $connection, LocalContactReference $localReference): ?ContactMapping
    {
        $this->validateConnection($connection);
        $row = $this->database()->table($this->table)
            ->where('local_identity_hash', ContactIdentityHasher::local($connection, $localReference))
            ->first();

        if ($row === null) {
            return null;
        }

        $mapping = $this->mapping($row);
        $this->assertLocalIdentity($mapping, $connection, $localReference);

        return $mapping;
    }

    public function findByRemoteId(string $connection, int $remoteId): ?ContactMapping
    {
        $this->validateConnection($connection);
        $this->validateRemoteId($remoteId);
        $row = $this->database()->table($this->table)
            ->where('remote_identity_hash', ContactIdentityHasher::remote($connection, $remoteId))
            ->first();

        if ($row === null) {
            return null;
        }

        $mapping = $this->mapping($row);
        if ($mapping->connection !== $connection || $mapping->remoteId !== $remoteId) {
            throw new IncompatibleContactMappingData('Stored contact mapping identity does not match its lookup hash.');
        }

        return $mapping;
    }

    public function claim(string $connection, LocalContactReference $localReference, int $remoteId): ContactMapping
    {
        $this->validateConnection($connection);
        $this->validateRemoteId($remoteId);
        $now = date('Y-m-d H:i:s');
        $this->database()->table($this->table)->insertOrIgnore([
            'connection' => $connection,
            'local_scope' => $localReference->scope,
            'local_key' => $localReference->key,
            'local_identity_hash' => ContactIdentityHasher::local($connection, $localReference),
            'remote_id' => $remoteId,
            'remote_identity_hash' => ContactIdentityHasher::remote($connection, $remoteId),
            'remote_state' => RemoteContactState::Active->value,
            'merged_into_remote_id' => null,
            'generation' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $local = $this->find($connection, $localReference);
        $remote = $this->findByRemoteId($connection, $remoteId);
        if ($local !== null && $remote !== null
            && $local->remoteId === $remoteId
            && $remote->localReference->scope === $localReference->scope
            && $remote->localReference->key === $localReference->key) {
            return $local;
        }

        if ($local !== null || $remote !== null) {
            throw new ContactMappingConflict($connection);
        }

        throw new RuntimeException('The contact mapping claim could not be persisted.');
    }

    public function markRemoteState(
        ContactMapping $expected,
        RemoteContactState $state,
        ?int $mergedIntoRemoteId = null,
    ): ContactMapping {
        $this->assertCurrent($expected);
        if ($state === RemoteContactState::Merged) {
            if ($mergedIntoRemoteId === null || $mergedIntoRemoteId <= 0 || $mergedIntoRemoteId === $expected->remoteId) {
                throw new InvalidContactIdentity('A merged mapping requires a different positive target remote ID.');
            }
        } elseif ($mergedIntoRemoteId !== null) {
            throw new InvalidContactIdentity('Only a merged mapping may retain a merge target.');
        }

        $updated = $this->expectedQuery($expected)->update([
            'remote_state' => $state->value,
            'merged_into_remote_id' => $mergedIntoRemoteId,
            'generation' => $expected->generation + 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        if ($updated !== 1) {
            throw new ContactMappingChanged($expected->connection);
        }

        return $this->find($expected->connection, $expected->localReference)
            ?? throw new RuntimeException('The updated contact mapping could not be read.');
    }

    public function relink(ContactMapping $expected, int $newRemoteId): ContactMapping
    {
        $this->assertCurrent($expected);
        $this->validateRemoteId($newRemoteId);
        if ($newRemoteId === $expected->remoteId) {
            throw new InvalidContactIdentity('Recovery requires a different remote contact ID.');
        }

        try {
            $updated = $this->expectedQuery($expected)->update([
                'remote_id' => $newRemoteId,
                'remote_identity_hash' => ContactIdentityHasher::remote($expected->connection, $newRemoteId),
                'remote_state' => RemoteContactState::Active->value,
                'merged_into_remote_id' => null,
                'generation' => $expected->generation + 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (QueryException $exception) {
            if ($this->findByRemoteId($expected->connection, $newRemoteId) !== null) {
                throw new ContactMappingConflict($expected->connection);
            }

            throw $exception;
        }

        if ($updated !== 1) {
            throw new ContactMappingChanged($expected->connection);
        }

        return $this->find($expected->connection, $expected->localReference)
            ?? throw new RuntimeException('The recovered contact mapping could not be read.');
    }

    private function expectedQuery(ContactMapping $expected): Builder
    {
        return $this->database()->table($this->table)
            ->where('local_identity_hash', ContactIdentityHasher::local(
                $expected->connection,
                $expected->localReference,
            ))
            ->where('remote_identity_hash', ContactIdentityHasher::remote(
                $expected->connection,
                $expected->remoteId,
            ))
            ->where('generation', $expected->generation);
    }

    private function assertCurrent(ContactMapping $expected): void
    {
        $current = $this->find($expected->connection, $expected->localReference);
        if ($current === null || $current->remoteId !== $expected->remoteId
            || $current->generation !== $expected->generation) {
            throw new ContactMappingChanged($expected->connection);
        }
    }

    private function database(): ConnectionInterface
    {
        return $this->connections->connection($this->databaseConnection);
    }

    private function mapping(object $row): ContactMapping
    {
        $connection = $row->connection ?? null;
        $scope = $row->local_scope ?? null;
        $key = $row->local_key ?? null;
        $remoteId = $row->remote_id ?? null;
        $localHash = $row->local_identity_hash ?? null;
        $remoteHash = $row->remote_identity_hash ?? null;
        $state = $row->remote_state ?? null;
        $mergedIntoRemoteId = $row->merged_into_remote_id ?? null;
        $generation = $row->generation ?? null;
        if (! is_string($connection) || ! is_string($scope) || ! is_string($key)
            || ! is_int($remoteId) || ! is_string($localHash) || ! is_string($remoteHash)
            || ! is_string($state)
            || ($mergedIntoRemoteId !== null && ! is_int($mergedIntoRemoteId))
            || ! is_int($generation) || $generation < 1) {
            throw new RuntimeException('Stored contact mapping data is invalid.');
        }

        $remoteState = RemoteContactState::tryFrom($state)
            ?? throw new RuntimeException('Stored contact mapping state is invalid.');

        $mapping = new ContactMapping(
            $connection,
            new LocalContactReference($scope, $key),
            $remoteId,
            $remoteState,
            $mergedIntoRemoteId,
            $generation,
        );
        if (! hash_equals(ContactIdentityHasher::local($connection, $mapping->localReference), $localHash)
            || ! hash_equals(ContactIdentityHasher::remote($connection, $remoteId), $remoteHash)) {
            throw new IncompatibleContactMappingData('Stored contact mapping identity hashes are invalid.');
        }

        return $mapping;
    }

    private function assertLocalIdentity(
        ContactMapping $mapping,
        string $connection,
        LocalContactReference $localReference,
    ): void {
        if ($mapping->connection !== $connection
            || $mapping->localReference->scope !== $localReference->scope
            || $mapping->localReference->key !== $localReference->key) {
            throw new IncompatibleContactMappingData('Stored contact mapping identity does not match its lookup hash.');
        }
    }

    private function validateConnection(string $connection): void
    {
        if (trim($connection) === '' || strlen($connection) > 191) {
            throw new InvalidContactIdentity('A non-empty connection name of at most 191 bytes is required.');
        }
    }

    private function validateRemoteId(int $remoteId): void
    {
        if ($remoteId <= 0) {
            throw new InvalidContactIdentity('A positive remote contact ID is required.');
        }
    }
}
