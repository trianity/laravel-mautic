<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Trianity\LaravelMautic\Identity\ContactIdentityHasher;

beforeEach(function () {
    config()->set('database.connections.outbox-upgrade', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    config()->set('mautic.outbox.database_connection', 'outbox-upgrade');
    DB::purge('outbox-upgrade');
});

it('upgrades an existing outbox with connection identity and conservative delivery stages', function () {
    $schema = Schema::connection('outbox-upgrade');
    $schema->create('mautic_outbox_commands', function (Blueprint $table): void {
        $table->id();
        $table->string('operation_id', 64)->unique();
        $table->string('connection', 191);
        $table->string('local_scope', 191);
        $table->string('local_key', 191);
        $table->char('aggregate_hash', 64);
        $table->unsignedBigInteger('revision');
        $table->string('operation', 16);
        $table->string('source', 64);
        $table->text('payload');
        $table->string('status', 24);
        $table->unsignedInteger('attempts')->default(0);
        $table->unsignedBigInteger('next_attempt_at')->nullable();
        $table->string('lease_owner', 64)->nullable();
        $table->unsignedBigInteger('lease_expires_at')->nullable();
        $table->string('last_failure_kind', 32)->nullable();
        $table->unsignedSmallInteger('last_http_status')->nullable();
        $table->string('last_error_code', 64)->nullable();
        $table->unsignedBigInteger('remote_id')->nullable();
        $table->unsignedBigInteger('dispatched_at')->nullable();
        $table->unsignedBigInteger('completed_at')->nullable();
        $table->timestamps();
    });
    DB::connection('outbox-upgrade')->table('mautic_outbox_commands')->insert([
        'operation_id' => 'legacy-processing',
        'connection' => 'Main',
        'local_scope' => 'tenant:acme',
        'local_key' => 'contact-1',
        'aggregate_hash' => str_repeat('a', 64),
        'revision' => 1,
        'operation' => 'update',
        'source' => 'host',
        'payload' => '{}',
        'status' => 'processing',
        'attempts' => 1,
    ]);
    DB::connection('outbox-upgrade')->table('mautic_outbox_commands')->insert([
        'operation_id' => 'legacy-superseded',
        'connection' => 'Main',
        'local_scope' => 'tenant:acme',
        'local_key' => 'contact-1',
        'aggregate_hash' => str_repeat('a', 64),
        'revision' => 2,
        'operation' => 'update',
        'source' => 'host',
        'payload' => '{}',
        'status' => 'superseded',
        'attempts' => 0,
    ]);

    $this->artisan('vendor:publish', ['--tag' => 'mautic-outbox-migrations', '--force' => true])
        ->assertSuccessful();
    (require database_path('migrations/2026_10_19_021000_correct_mautic_contact_outbox.php'))->up();
    $row = DB::connection('outbox-upgrade')->table('mautic_outbox_commands')->where('operation_id', 'legacy-processing')->first();

    expect($row?->connection_hash)->toBe(ContactIdentityHasher::connection('Main'))
        ->and($row?->delivery_stage)->toBe('operation_in_flight')
        ->and(DB::connection('outbox-upgrade')->table('mautic_outbox_commands')->where('operation_id', 'legacy-superseded')->value('status'))->toBe('pending')
        ->and($schema->hasTable('mautic_outbox_connection_blocks'))->toBeTrue();
});
