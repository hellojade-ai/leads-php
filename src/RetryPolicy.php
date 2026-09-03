<?php

declare(strict_types=1);

namespace HelloJade\Intake;

/**
 * Rule 5: retry on 5xx, on transport errors, and on 429 after Retry-After.
 * Never on any other 4xx.
 *
 * - `$maxAttempts` — delivery attempts. A 429 does not consume one.
 * - `$maxRateLimitWaits` — how many 429s to wait out before giving up.
 * - `$baseDelay` / `$maxDelay` — exponential backoff bounds, in seconds.
 * - `$jitter` — up to this many seconds of random extra delay, so a fleet of
 *   workers recovering from the same outage does not retry in lockstep.
 */
final class RetryPolicy
{
    /** @var callable(): float returns a float in [0, 1) */
    private $random;

    /**
     * @param (callable(): float)|null $random injectable randomness for tests
     */
    public function __construct(
        public readonly int $maxAttempts = 5,
        public readonly int $maxRateLimitWaits = 10,
        public readonly float $baseDelay = 1.0,
        public readonly float $maxDelay = 30.0,
        public readonly float $jitter = 0.5,
        ?callable $random = null,
    ) {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('maxAttempts must be >= 1');
        }
        $this->random = $random ?? static fn (): float => mt_rand() / (mt_getrandmax() + 1);
    }

    /** Seconds to wait before attempt n+1 (n starts at 1). */
    public function backoff(int $n): float
    {
        return min($this->baseDelay * (2 ** ($n - 1)), $this->maxDelay) + (($this->random)() * $this->jitter);
    }

    /**
     * Seconds to wait after a 429: the server's Retry-After is a floor, not a
     * strategy, so the wait grows with each consecutive 429.
     */
    public function rateLimitDelay(int $retryAfter, int $waits): float
    {
        return max((float) $retryAfter, $this->backoff($waits));
    }

    /** One attempt, no waiting. For callers that run their own retry loop. */
    public static function none(): self
    {
        return new self(maxAttempts: 1, maxRateLimitWaits: 0);
    }
}
