<?php

declare(strict_types=1);

namespace HelloJade\Intake\Exception;

/**
 * 401 — the key is missing, mistyped, revoked, or pointed at the wrong host.
 */
class UnauthorizedException extends ApiException
{
}
