<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\DataKey;
use App\Models\User;
use App\Security\DataKeys;
use App\Security\KmsKeyWrapper;
use App\Security\LocalKeyWrapper;
use Aws\CommandInterface;
use Aws\Kms\KmsClient;
use Aws\MockHandler;
use Aws\Result;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class CredentialEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_secrets_are_unreadable_at_rest_and_readable_by_the_owner(): void
    {
        $connection = Connection::factory()->withHeader('Bearer sk-live-123')->create();

        $stored = (string) DB::table('connections')->where('id', $connection->id)->value('secrets');
        $this->assertStringNotContainsString('sk-live-123', $stored);

        $this->assertSame('Bearer sk-live-123', Connection::query()->find($connection->id)->secret('header_value'));
    }

    public function test_each_user_has_their_own_data_key(): void
    {
        $first = Connection::factory()->withHeader('same-secret')->create();
        $second = Connection::factory()->withHeader('same-secret')->create();

        $this->assertSame(2, DataKey::query()->count());
        $this->assertNotSame(
            DB::table('data_keys')->where('user_id', $first->user_id)->value('wrapped_key'),
            DB::table('data_keys')->where('user_id', $second->user_id)->value('wrapped_key'),
        );
    }

    public function test_a_wrapped_key_moved_to_another_user_does_not_unwrap(): void
    {
        $victim = User::factory()->create();
        $attacker = User::factory()->create();
        app(DataKeys::class)->forUser($victim->id);

        DataKey::query()->create([
            'user_id' => $attacker->id,
            'driver' => 'local',
            'wrapped_key' => DataKey::query()->where('user_id', $victim->id)->value('wrapped_key'),
        ]);

        $this->expectException(RuntimeException::class);

        (new DataKeys(new LocalKeyWrapper(config('nexus.encryption.local.master_key'))))->forUser($attacker->id);
    }

    public function test_deleting_the_data_key_makes_stored_secrets_unreadable(): void
    {
        $connection = Connection::factory()->withHeader('Bearer sk-live-123')->create();

        DataKey::query()->where('user_id', $connection->user_id)->delete();
        app()->forgetScopedInstances();

        $this->expectException(DecryptException::class);

        Connection::query()->find($connection->id)->secret('header_value');
    }

    public function test_the_kms_wrapper_binds_each_key_to_its_user(): void
    {
        $commands = [];
        $handler = new MockHandler;
        $handler->append(
            function (CommandInterface $command) use (&$commands): Result {
                $commands[] = $command;

                return new Result(['CiphertextBlob' => 'wrapped-by-kms']);
            },
            function (CommandInterface $command) use (&$commands): Result {
                $commands[] = $command;

                return new Result(['Plaintext' => 'plain-data-key']);
            },
        );

        $wrapper = new KmsKeyWrapper(new KmsClient([
            'version' => '2014-11-01',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler' => $handler,
        ]), 'alias/nexus');

        $wrapped = $wrapper->wrap('plain-data-key', 42);

        $this->assertSame('plain-data-key', $wrapper->unwrap($wrapped, 42));
        $this->assertSame('Encrypt', $commands[0]->getName());
        $this->assertSame('alias/nexus', $commands[0]['KeyId']);
        $this->assertSame(['app' => 'nexus', 'user' => '42'], $commands[0]['EncryptionContext']);
        $this->assertSame('wrapped-by-kms', $commands[1]['CiphertextBlob']);
        $this->assertSame(['app' => 'nexus', 'user' => '42'], $commands[1]['EncryptionContext']);
    }
}
