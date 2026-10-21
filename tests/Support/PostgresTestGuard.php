<?php

namespace Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use LogicException;

final class PostgresTestGuard
{
    public function __construct(public readonly array $identity)
    {
        foreach (['database', 'oid', 'owner', 'address', 'port', 'host', 'connection_port', 'token', 'cache'] as $key) {
            if (! isset($identity[$key]) || ! is_string($identity[$key])) {
                throw new LogicException('context_invalid');
            }
        }
        if (! preg_match('/\Asilansia_test_[a-f0-9]{16}\z/', $identity['database'])
            || ! preg_match('/\A[a-f0-9]{64}\z/', $identity['token'])
            || ! ctype_digit($identity['oid']) || $identity['owner'] === ''
            || ! is_dir(dirname($identity['cache']))
            || ! str_starts_with($identity['cache'], sys_get_temp_dir().DIRECTORY_SEPARATOR.'silansia-phase1-')) {
            throw new LogicException('context_invalid');
        }
    }

    public static function fromEnvironment(): self
    {
        $identity = json_decode(getenv('SILANSIA_TEST_IDENTITY') ?: '', true);
        if (! is_array($identity)) {
            throw new LogicException('context_missing');
        }

        return new self($identity);
    }

    public function assertCacheIsolated(): void
    {
        if (getenv('APP_CONFIG_CACHE') !== $this->identity['cache']) {
            throw new LogicException('cache_path_mismatch');
        }
        if (file_exists($this->identity['cache']) || is_link($this->identity['cache'])) {
            throw new LogicException('cache_present');
        }
    }

    public function assertSafe(Application $app, Connection $connection): void
    {
        $this->assertCacheIsolated();
        if (! $app->environment('testing') || $app->configurationIsCached()
            || $app['config']->get('database.default') !== 'silansia_testing'
            || $app['config']->get('database.connections.pgsql.database') === $this->identity['database']) {
            throw new LogicException('environment_mismatch');
        }
        $this->assertTarget($connection);
    }

    public function assertTarget(Connection $connection): void
    {
        if ($connection->getName() !== 'silansia_testing' || $connection->getDriverName() !== 'pgsql'
            || $connection->getConfig('url') !== null
            || $connection->getConfig('database') !== $this->identity['database']
            || $connection->getConfig('host') !== $this->identity['host']
            || (string) $connection->getConfig('port') !== $this->identity['connection_port']
            || $connection->getConfig('username') !== $this->identity['owner']) {
            throw new LogicException('connection_mismatch');
        }

        $actual = $connection->selectOne("SELECT current_database() AS name, current_user AS owner, d.oid::text AS oid,
            COALESCE(inet_server_addr()::text, '') AS address, COALESCE(inet_server_port()::text, '') AS port,
            shobj_description(d.oid, 'pg_database') AS marker, pg_get_userbyid(d.datdba) AS database_owner
            FROM pg_catalog.pg_database d WHERE d.datname = current_database()");
        if (! $actual || $actual->name !== $this->identity['database']
            || $actual->owner !== $this->identity['owner'] || $actual->database_owner !== $this->identity['owner']
            || $actual->oid !== $this->identity['oid'] || $actual->address !== $this->identity['address']
            || $actual->port !== $this->identity['port'] || $actual->marker !== $this->marker()) {
            throw new LogicException('identity_mismatch');
        }
    }

    public function assertOwnedForCleanup(Connection $admin): void
    {
        $actual = $admin->selectOne("SELECT d.oid::text AS oid, current_database() AS connected_database,
            current_user AS owner, COALESCE(inet_server_addr()::text, '') AS address,
            COALESCE(inet_server_port()::text, '') AS port,
            shobj_description(d.oid, 'pg_database') AS marker, pg_get_userbyid(d.datdba) AS database_owner
            FROM pg_catalog.pg_database d WHERE d.datname = ?", [$this->identity['database']]);
        if (! $actual || $actual->connected_database === $this->identity['database']
            || $actual->oid !== $this->identity['oid'] || $actual->marker !== $this->marker()
            || $actual->owner !== $this->identity['owner'] || $actual->database_owner !== $this->identity['owner']
            || $actual->address !== $this->identity['address'] || $actual->port !== $this->identity['port']) {
            throw new LogicException('cleanup_identity_mismatch');
        }
    }

    private function marker(): string
    {
        return 'silansia-phase1:'.$this->identity['token'];
    }
}
