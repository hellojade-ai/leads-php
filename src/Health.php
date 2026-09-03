<?php

declare(strict_types=1);

namespace HelloJade\Intake;

/**
 * GET /healthz. Returned for both 200 and 503 — a 503 is a report, not a
 * failure of the call.
 */
final class Health
{
    public function __construct(
        public readonly bool $ok,
        public readonly bool $storeWritable,
        public readonly ?int $pending,
        public readonly ?int $dead,
        public readonly ?int $oldestPendingAgeS,
        public readonly int $httpStatus,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromResponse(int $httpStatus, array $body): self
    {
        return new self(
            ok: ($body['ok'] ?? false) === true,
            storeWritable: ($body['store_writable'] ?? false) === true,
            pending: isset($body['pending']) ? (int) $body['pending'] : null,
            dead: isset($body['dead']) ? (int) $body['dead'] : null,
            oldestPendingAgeS: isset($body['oldest_pending_age_s']) ? (int) $body['oldest_pending_age_s'] : null,
            httpStatus: $httpStatus,
        );
    }
}
