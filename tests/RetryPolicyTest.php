<?php

declare(strict_types=1);

namespace HelloJade\Intake\Tests;

use HelloJade\Intake\RetryPolicy;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase
{
    public function testDefaults(): void
    {
        $p = new RetryPolicy();
        self::assertSame(5, $p->maxAttempts);
        self::assertSame(10, $p->maxRateLimitWaits);
        self::assertSame(1.0, $p->baseDelay);
        self::assertSame(30.0, $p->maxDelay);
        self::assertSame(0.5, $p->jitter);
    }

    public function testBackoffDoublesAndCaps(): void
    {
        $p = new RetryPolicy(jitter: 0.0);
        self::assertSame([1.0, 2.0, 4.0, 8.0, 16.0, 30.0, 30.0], array_map([$p, 'backoff'], [1, 2, 3, 4, 5, 6, 7]));
    }

    public function testJitterAddsAtMostJitterSeconds(): void
    {
        $p = new RetryPolicy(jitter: 0.5, random: static fn (): float => 0.999);
        self::assertGreaterThan(1.0, $p->backoff(1));
        self::assertLessThan(1.5, $p->backoff(1));
    }

    public function testRateLimitDelayIsAFloor(): void
    {
        $p = new RetryPolicy(jitter: 0.0);
        self::assertSame(3.0, $p->rateLimitDelay(3, 1));
        self::assertSame(4.0, $p->rateLimitDelay(1, 3));
    }

    public function testNoneMakesOneAttempt(): void
    {
        self::assertSame(1, RetryPolicy::none()->maxAttempts);
        self::assertSame(0, RetryPolicy::none()->maxRateLimitWaits);
    }

    public function testRejectsZeroAttempts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RetryPolicy(maxAttempts: 0);
    }
}
