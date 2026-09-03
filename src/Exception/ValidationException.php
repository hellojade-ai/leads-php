<?php

declare(strict_types=1);

namespace HelloJade\Intake\Exception;

/**
 * 422 — `$fields` maps every failing field to a reason ("required",
 * "too_long", "out_of_range"). The API lists all of them at once; do not stop
 * at the first.
 */
class ValidationException extends ApiException
{
    /**
     * @param array<string, string> $fields
     */
    public function __construct(
        public readonly array $fields,
        int $status,
        string $apiCode,
        ?string $message = null,
        ?string $requestId = null,
        ?string $body = null,
        int $attempts = 1,
    ) {
        parent::__construct($status, $apiCode, $message, $requestId, $body, $attempts);
        if ($fields !== []) {
            $pairs = [];
            foreach ($fields as $k => $v) {
                $pairs[] = "{$k}:{$v}";
            }
            $this->message .= ' fields=' . implode(',', $pairs);
        }
    }
}
