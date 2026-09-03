<?php

declare(strict_types=1);

namespace HelloJade\Intake;

/**
 * The body of a 202 (status "accepted") or a 200 (status "duplicate"). Both
 * are success. `$flags` is always an array and flags are never errors
 * (rule 8). `$source` is the label of the API key that submitted the lead.
 */
final class Accepted
{
    /**
     * @param list<string> $flags
     */
    public function __construct(
        public readonly string $eventId,
        public readonly string $status,
        public readonly ?string $receivedAt,
        public readonly array $flags,
        public readonly ?string $source,
        public readonly ?string $requestId,
        public readonly int $httpStatus,
    ) {
    }

    public function isAccepted(): bool
    {
        return $this->status === 'accepted';
    }

    public function isDuplicate(): bool
    {
        return $this->status === 'duplicate';
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromResponse(int $httpStatus, array $body, ?string $requestId): self
    {
        $flags = [];
        foreach ((array) ($body['flags'] ?? []) as $f) {
            $flags[] = (string) $f;
        }

        return new self(
            eventId: (string) ($body['event_id'] ?? ''),
            status: (string) ($body['status'] ?? ''),
            receivedAt: isset($body['received_at']) ? (string) $body['received_at'] : null,
            flags: $flags,
            source: isset($body['source']) ? (string) $body['source'] : null,
            requestId: $requestId,
            httpStatus: $httpStatus,
        );
    }
}
