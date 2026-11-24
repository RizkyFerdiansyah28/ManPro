<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Database\PostgresConnection;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\BillingC2Cluster;
use Tests\Support\BillingMinimumDemo;
use WeakReference;

final class BillingC2ConnectionLifecycleTest extends TestCase
{
    public function test_connections_release_in_order_before_smart_shutdown(): void
    {
        $events = [];
        $target = self::connection('target', $events);
        $neutral = self::connection('neutral', $events);
        $targetPdo = WeakReference::create($target->getRawPdo());
        $neutralPdo = WeakReference::create($neutral->getRawPdo());

        $oid = BillingC2Cluster::withDisconnectedConnection($neutral,
            static function (PostgresConnection $admin) use ($target, $targetPdo, &$events): string {
                BillingC2Cluster::withDisconnectedConnection($target, static function () use (&$events): void {
                    $events[] = 'target verification';
                });
                self::assertNull($targetPdo->get());
                self::assertInstanceOf(PDO::class, $admin->getRawPdo());
                $events[] = 'DROP';
                $events[] = 'DROP postcheck';
                $events[] = 'final cluster and peer checks';

                return '16384';
            },
            static function () use ($neutral, $neutralPdo, &$events): void {
                self::assertNull($neutral->getRawPdo());
                self::assertNull($neutral->getRawReadPdo());
                self::assertNull($neutral->getRawDirectPdo());
                self::assertNull($neutralPdo->get());
                $events[] = 'smart shutdown';
            },
        );

        self::assertSame('16384', $oid);
        self::assertSame(['target verification', 'disconnect target', 'DROP', 'DROP postcheck', 'final cluster and peer checks',
            'disconnect neutral', 'smart shutdown'], $events);
    }

    #[DataProvider('demoFailureStages')]
    public function test_demo_failure_allows_the_owner_to_finish_guarded_cleanup(string $stage): void
    {
        $events = [];
        $neutral = self::connection('neutral', $events);
        $result = BillingC2Cluster::withDisconnectedConnection($neutral,
            static function (PostgresConnection $admin) use ($stage, &$events): array {
                $result = BillingMinimumDemo::scenarioResult(static function () use ($stage, &$events): void {
                    $events[] = $stage;
                    throw new RuntimeException('synthetic scenario failure');
                }, $stage);

                self::assertInstanceOf(PDO::class, $admin->getRawPdo());
                $events[] = 'recheck ownership and peers';
                $events[] = 'DROP and postcheck';

                return $result;
            },
            static function () use ($neutral, &$events): void {
                self::assertNull($neutral->getRawPdo());
                $events[] = 'smart shutdown';
            },
        );

        self::assertSame(['status' => 'FAIL', 'stage' => $stage], $result);
        self::assertSame([$stage, 'recheck ownership and peers', 'DROP and postcheck',
            'disconnect neutral', 'smart shutdown'], $events);
    }

    public static function demoFailureStages(): iterable
    {
        yield 'fixture exception' => ['demo_fixture'];
        yield 'scanner exception' => ['demo_scan'];
        yield 'HTTP read exception' => ['demo_proof'];
    }

    #[DataProvider('demoBlockedErrors')]
    public function test_demo_does_not_enter_destructive_cleanup_when_state_is_ambiguous(string $reason): void
    {
        $stage = 'demo_verify';
        $events = [];
        $neutral = self::connection('neutral', $events);
        try {
            BillingC2Cluster::withDisconnectedConnection($neutral,
                static function () use ($reason, &$stage): void {
                    BillingMinimumDemo::scenarioResult(static function () use ($reason): never {
                        throw new LogicException($reason);
                    }, $stage);
                    self::fail('Must not reach guarded DROP');
                }, self::shutdownMustNotRun(...));
            self::fail('Expected demo to stop');
        } catch (LogicException $error) {
            self::assertSame($reason, $error->getMessage());
        }
        self::assertSame(['disconnect neutral'], $events);
    }

    public static function demoBlockedErrors(): iterable
    {
        yield 'financial or scanner outcome unknown' => ['billing_demo_outcome_unconfirmed'];
        yield 'ownership check failed' => ['billing_demo_ownership_unverified'];
        yield 'observer found an identity mismatch' => ['identity_mismatch'];
    }

    public function test_demo_failure_cannot_override_cleanup_ownership_guard(): void
    {
        $stage = 'demo_fixture';
        $events = [];
        $neutral = self::connection('neutral', $events);
        try {
            BillingC2Cluster::withDisconnectedConnection($neutral,
                static function () use (&$stage, &$events): never {
                    $result = BillingMinimumDemo::scenarioResult(static function (): never {
                        throw new RuntimeException('synthetic fixture error');
                    }, $stage);
                    self::assertSame(['status' => 'FAIL', 'stage' => $stage], $result);
                    $events[] = 'recheck ownership';
                    throw new LogicException('synthetic cleanup guard refused');
                }, self::shutdownMustNotRun(...));
            self::fail('Cleanup guard failure was ignored');
        } catch (LogicException $error) {
            self::assertSame('synthetic cleanup guard refused', $error->getMessage());
        }
        self::assertSame(['recheck ownership', 'disconnect neutral'], $events);
    }

    public function test_failed_drop_postcheck_disconnects_without_starting_shutdown(): void
    {
        $events = [];
        $neutral = self::connection('neutral', $events);
        $failure = new LogicException('synthetic DROP postcheck failure');

        try {
            BillingC2Cluster::withDisconnectedConnection($neutral, static function () use ($failure, &$events): void {
                $events[] = 'DROP postcheck';
                throw $failure;
            }, self::shutdownMustNotRun(...));
            self::fail('Expected the postcheck failure');
        } catch (LogicException $error) {
            self::assertSame($failure, $error);
        }

        self::assertNull($neutral->getRawPdo());
        self::assertSame(['DROP postcheck', 'disconnect neutral'], $events);
    }

    public function test_failed_target_verification_disconnects_both_connections(): void
    {
        $events = [];
        $target = self::connection('target', $events);
        $neutral = self::connection('neutral', $events);
        $failure = new LogicException('synthetic target verification failure');

        try {
            BillingC2Cluster::withDisconnectedConnection($neutral, static function () use ($target, $failure): void {
                BillingC2Cluster::withDisconnectedConnection($target, static function () use ($failure): void {
                    throw $failure;
                });
            }, self::shutdownMustNotRun(...));
            self::fail('Expected the target verification failure');
        } catch (LogicException $error) {
            self::assertSame($failure, $error);
        }

        self::assertNull($target->getRawPdo());
        self::assertNull($neutral->getRawPdo());
        self::assertSame(['disconnect target', 'disconnect neutral'], $events);
    }

    #[DataProvider('releaseFailures')]
    public function test_disconnect_failure_prevents_shutdown(string $targetMode, string $neutralMode, string $reason, array $expectedEvents): void
    {
        $events = [];
        $target = self::connection('target', $events, $targetMode);
        $neutral = self::connection('neutral', $events, $neutralMode);

        try {
            BillingC2Cluster::withDisconnectedConnection($neutral, static function () use ($target, &$events): void {
                BillingC2Cluster::withDisconnectedConnection($target, static function (): void {});
                $events[] = 'DROP postcheck';
            }, self::shutdownMustNotRun(...));
            self::fail('Expected the connection release failure');
        } catch (LogicException $error) {
            self::assertSame($reason, $error->getMessage());
        }

        self::assertSame($expectedEvents, $events);
    }

    public static function releaseFailures(): iterable
    {
        yield 'target throws before DROP' => ['throws', 'normal', 'billing_c2_disconnect_failed', ['disconnect target', 'disconnect neutral']];
        yield 'neutral throws after postcheck' => ['normal', 'throws', 'billing_c2_disconnect_failed', ['disconnect target', 'DROP postcheck', 'disconnect neutral']];
        yield 'neutral holds PDO after disconnect' => ['normal', 'still_open', 'billing_c2_connection_release_unverified',
            ['disconnect target', 'DROP postcheck', 'disconnect neutral']];
    }

    public function test_external_pdo_reference_prevents_shutdown_even_when_laravel_slots_are_empty(): void
    {
        $events = [];
        $neutral = self::connection('neutral', $events);
        $held = $neutral->getRawPdo();

        try {
            BillingC2Cluster::withDisconnectedConnection($neutral, static function (): void {}, self::shutdownMustNotRun(...));
            self::fail('Expected the retained PDO reference to block shutdown');
        } catch (LogicException $error) {
            self::assertSame('billing_c2_connection_release_unverified', $error->getMessage());
        }

        self::assertInstanceOf(PDO::class, $held);
        self::assertNull($neutral->getRawPdo());
        self::assertSame(['disconnect neutral'], $events);
    }

    private static function shutdownMustNotRun(): never
    {
        self::fail('Smart shutdown started despite a failed postcheck or unverified disconnect');
    }

    private static function connection(string $name, array &$events, string $mode = 'normal'): PostgresConnection
    {
        return new class($name, $events, $mode) extends PostgresConnection
        {
            private array $recordedEvents;

            public function __construct(string $name, array &$events, private readonly string $mode)
            {
                $this->recordedEvents = &$events;
                parent::__construct(new class extends PDO
                {
                    public function __construct() {}
                }, $name);
            }

            public function disconnect(): void
            {
                $this->recordedEvents[] = 'disconnect '.$this->getDatabaseName();
                if ($this->mode === 'throws') {
                    throw new RuntimeException('synthetic disconnect failure');
                }
                if ($this->mode === 'normal') {
                    parent::disconnect();
                }
            }
        };
    }
}
