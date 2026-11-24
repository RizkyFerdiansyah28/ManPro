<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Billing\BillingTime;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\PaymentR2IntegrationFixtures;

final class PaymentR2PolicyFixtureTest extends TestCase
{
    private const POLICY = '9cbefd8a-b00a-4c06-aacd-8d0b57fc50c7';

    public function test_policy_creation_commits_before_head_activation(): void
    {
        $events = [];
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::exactly(2))->method('beginTransaction')->willReturnCallback(function () use (&$events): bool {
            $events[] = 'begin';

            return true;
        });
        $pdo->expects(self::exactly(2))->method('commit')->willReturnCallback(function () use (&$events): bool {
            $events[] = 'commit';

            return true;
        });
        $connection = $this->getMockBuilder(PostgresConnection::class)->setConstructorArgs([$pdo, 'synthetic'])
            ->onlyMethods(['scalar'])->getMock();
        $connection->expects(self::exactly(2))->method('scalar')->willReturnCallback(
            function (string $query, array $bindings) use ($connection, &$events): string|int {
                self::assertSame(1, $connection->transactionLevel());
                if (str_contains($query, 'proof_scan_create_policy')) {
                    self::assertSame(['begin'], $events);
                    self::assertSame('SELECT public.proof_scan_create_policy(?::jsonb, public.proof_scan_authority())', $query);
                    self::assertSame([json_encode(PaymentR2IntegrationFixtures::policyProfile(), JSON_THROW_ON_ERROR)], $bindings);
                    $events[] = 'create';

                    return self::POLICY;
                }
                self::assertSame(['begin', 'create', 'commit', 'begin'], $events);
                self::assertSame('SELECT public.proof_scan_set_head(?,?,?,?,public.proof_scan_authority())', $query);
                self::assertTrue(BillingTime::uuid($bindings[1]));
                self::assertSame([self::POLICY, $bindings[1], 0, 'active'], $bindings);
                $events[] = 'activate';

                return 1;
            },
        );

        $this->createPolicyOn($connection);

        self::assertSame(0, $connection->transactionLevel());
        self::assertSame(['begin', 'create', 'commit', 'begin', 'activate', 'commit'], $events);
    }

    public function test_policy_cannot_be_created_inside_an_outer_transaction(): void
    {
        $connection = $this->createMock(PostgresConnection::class);
        $connection->method('transactionLevel')->willReturn(1);
        $connection->expects(self::never())->method('transaction');
        $connection->expects(self::never())->method('scalar');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('fixture_policy_outer_transaction');

        $this->createPolicyOn($connection);
    }

    public function test_head_activation_is_not_attempted_when_policy_commit_fails(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('commit')->willThrowException(new RuntimeException('synthetic_commit_failed'));
        $connection = $this->getMockBuilder(PostgresConnection::class)->setConstructorArgs([$pdo, 'synthetic'])
            ->onlyMethods(['scalar'])->getMock();
        $connection->expects(self::once())->method('scalar')
            ->with('SELECT public.proof_scan_create_policy(?::jsonb, public.proof_scan_authority())')
            ->willReturn(self::POLICY);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('synthetic_commit_failed');

        $this->createPolicyOn($connection);
    }

    private function createPolicyOn(PostgresConnection $connection): void
    {
        $app = DB::getFacadeApplication();
        $container = new Container;
        $manager = $this->createMock(DatabaseManager::class);
        $manager->expects(self::once())->method('connection')->willReturn($connection);
        $container->instance('db', $manager);
        DB::clearResolvedInstance('db');
        DB::setFacadeApplication($container);

        try {
            (new PaymentR2IntegrationFixtures)->createPolicy();
        } finally {
            DB::clearResolvedInstance('db');
            DB::setFacadeApplication($app);
        }
    }
}
